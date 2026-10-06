<?php
/**
 * 24/7 - Employee Management API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        getEmployeeDetails($pdo, (int)$_GET['id']);
    } elseif ($action === 'available_for_shift') {
        getAvailableEmployeesForShift($pdo);
    } else {
        listEmployees($pdo);
    }
} elseif ($method === 'POST') {
    if ($action === 'update') {
        updateEmployee($pdo, $user);
    } elseif ($action === 'toggle_status') {
        toggleStatus($pdo, $user);
    } elseif ($action === 'delete') {
        deleteEmployee($pdo, $user);
    } else {
        createEmployee($pdo, $user);
    }
} else {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

function listEmployees(PDO $pdo): void {
    $search = trim($_GET['search'] ?? '');
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
    $deptId = isset($_GET['department_id']) && $_GET['department_id'] !== 'all' ? (int)$_GET['department_id'] : null;
    $shiftPref = trim($_GET['shift_preference'] ?? '');
    $status = trim($_GET['status'] ?? '');

    $sql = "
        SELECT e.*, b.name as branch_name, b.code as branch_code, d.name as department_name, d.code as department_code,
               u.id as user_id, u.username, u.role as user_role,
               (SELECT COUNT(*) FROM shift_assignments sa WHERE sa.employee_id = e.id AND sa.shift_date >= CURDATE()) as upcoming_shifts_count,
               (SELECT a.status FROM attendance a WHERE a.employee_id = e.id AND a.clock_out IS NULL LIMIT 1) as live_duty_status
        FROM employees e
        JOIN branches b ON e.branch_id = b.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN users u ON u.employee_id = e.id
        WHERE 1=1
    ";

    $params = [];
    if (!empty($search)) {
        $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR e.email LIKE ? OR e.role_title LIKE ?)";
        $wildcard = "%$search%";
        $params = array_merge($params, [$wildcard, $wildcard, $wildcard, $wildcard, $wildcard]);
    }
    if ($branchId !== null) {
        $sql .= " AND e.branch_id = ?";
        $params[] = $branchId;
    }
    if ($deptId !== null) {
        $sql .= " AND e.department_id = ?";
        $params[] = $deptId;
    }
    if (!empty($shiftPref) && $shiftPref !== 'all') {
        $sql .= " AND e.shift_preference = ?";
        $params[] = $shiftPref;
    }
    if (!empty($status) && $status !== 'all') {
        $sql .= " AND e.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY e.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    foreach ($employees as &$emp) {
        $emp['skills_array'] = !empty($emp['skills']) ? json_decode($emp['skills'], true) : [];
    }
    unset($emp);

    jsonResponse(['success' => true, 'employees' => $employees, 'count' => count($employees)]);
}

function getEmployeeDetails(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("
        SELECT e.*, b.name as branch_name, d.name as department_name, u.username, u.role as user_role, u.last_login
        FROM employees e
        JOIN branches b ON e.branch_id = b.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN users u ON u.employee_id = e.id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $employee = $stmt->fetch();

    if (!$employee) {
        jsonResponse(['success' => false, 'message' => 'Employee not found'], 404);
    }

    $employee['skills_array'] = !empty($employee['skills']) ? json_decode($employee['skills'], true) : [];

    // Recent Shifts
    $shiftStmt = $pdo->prepare("
        SELECT sa.*, st.name as shift_name, st.start_time, st.end_time, st.is_overnight, st.color
        FROM shift_assignments sa
        JOIN shift_templates st ON sa.shift_template_id = st.id
        WHERE sa.employee_id = ?
        ORDER BY sa.start_datetime DESC
        LIMIT 10
    ");
    $shiftStmt->execute([$id]);
    $employee['recent_shifts'] = $shiftStmt->fetchAll();

    // Recent Attendance
    $attStmt = $pdo->prepare("
        SELECT a.*, st.name as shift_name
        FROM attendance a
        LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
        LEFT JOIN shift_templates st ON sa.shift_template_id = st.id
        WHERE a.employee_id = ?
        ORDER BY a.clock_in DESC
        LIMIT 10
    ");
    $attStmt->execute([$id]);
    $employee['recent_attendance'] = $attStmt->fetchAll();

    jsonResponse(['success' => true, 'employee' => $employee]);
}

function createEmployee(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        jsonResponse(['success' => false, 'message' => 'Only administrators and managers can add employees'], 403);
    }

    $data = getRequestData();
    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $roleTitle = trim($data['role_title'] ?? '');
    $branchId = (int)($data['branch_id'] ?? 1);
    $deptId = (int)($data['department_id'] ?? 1);
    $empType = $data['employment_type'] ?? 'full_time';
    $shiftPref = $data['shift_preference'] ?? 'any';
    $rate = (float)($data['hourly_rate'] ?? 25.00);
    $maxHours = (int)($data['max_weekly_hours'] ?? 40);
    $hireDate = !empty($data['hire_date']) ? $data['hire_date'] : date('Y-m-d');
    $emergencyContact = trim($data['emergency_contact'] ?? '');
    $skills = is_array($data['skills'] ?? null) ? json_encode($data['skills']) : (string)($data['skills'] ?? '[]');

    if (empty($firstName) || empty($lastName) || empty($email) || empty($roleTitle)) {
        jsonResponse(['success' => false, 'message' => 'Please fill in all required fields (Name, Email, Role Title)'], 422);
    }

    // Check email uniqueness
    $checkStmt = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
    $checkStmt->execute([$email]);
    if ($checkStmt->fetch()) {
        jsonResponse(['success' => false, 'message' => 'An employee with this email already exists'], 422);
    }

    // Auto-generate employee code
    $countStmt = $pdo->query("SELECT COUNT(*) FROM employees");
    $nextNum = ((int)$countStmt->fetchColumn()) + 1;
    $empCode = sprintf('EMP-247-%02d', $nextNum);

    // Pick avatar color
    $colors = ['#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#6366f1'];
    $avatarColor = $colors[array_rand($colors)];

    $insertStmt = $pdo->prepare("
        INSERT INTO employees (branch_id, department_id, employee_code, first_name, last_name, email, phone, role_title, employment_type, shift_preference, hourly_rate, max_weekly_hours, skills, status, hire_date, emergency_contact, avatar_color)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)
    ");
    $insertStmt->execute([
        $branchId, $deptId, $empCode, $firstName, $lastName, $email, $phone, $roleTitle,
        $empType, $shiftPref, $rate, $maxHours, $skills, $hireDate, $emergencyContact, $avatarColor
    ]);
    $empId = (int)$pdo->lastInsertId();

    // Auto-create user login account if requested
    $createUserAccount = !empty($data['create_user_account']);
    if ($createUserAccount) {
        $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . '.' . $lastName));
        $systemRole = in_array($data['system_role'] ?? '', ['admin', 'manager', 'supervisor', 'staff'], true) ? $data['system_role'] : 'staff';
        $plainPass = $data['password'] ?? 'admin123';
        $passHash = password_hash($plainPass, PASSWORD_BCRYPT);

        // Ensure unique username
        $userCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $userCheck->execute([$username]);
        if ($userCheck->fetch()) {
            $username .= '_' . $empId;
        }

        $userStmt = $pdo->prepare("
            INSERT INTO users (employee_id, username, email, password_hash, role, status)
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $userStmt->execute([$empId, $username, $email, $passHash, $systemRole]);
    }

    logAudit($pdo, (int)$user['id'], 'EMPLOYEE_CREATE', 'employees', $empId, [
        'code' => $empCode,
        'name' => "$firstName $lastName",
        'role' => $roleTitle
    ], $branchId);

    createNotification(
        $pdo,
        'New Employee Enrolled',
        "$firstName $lastName ($roleTitle) enrolled. Available for shift scheduling.",
        'info',
        $branchId,
        'employees',
        $empId
    );

    jsonResponse([
        'success' => true,
        'message' => "Employee $firstName $lastName ($empCode) created successfully and is now available for shift scheduling!",
        'employee_id' => $empId,
        'employee_code' => $empCode
    ]);
}

function updateEmployee(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to update employee profile'], 403);
    }

    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Missing employee ID'], 422);
    }

    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $roleTitle = trim($data['role_title'] ?? '');
    $branchId = (int)($data['branch_id'] ?? 1);
    $deptId = (int)($data['department_id'] ?? 1);
    $empType = $data['employment_type'] ?? 'full_time';
    $shiftPref = $data['shift_preference'] ?? 'any';
    $rate = (float)($data['hourly_rate'] ?? 25.00);
    $maxHours = (int)($data['max_weekly_hours'] ?? 40);
    $status = in_array($data['status'] ?? '', ['active', 'inactive', 'on_leave'], true) ? $data['status'] : 'active';
    $emergencyContact = trim($data['emergency_contact'] ?? '');
    $skills = is_array($data['skills'] ?? null) ? json_encode($data['skills']) : (string)($data['skills'] ?? '[]');

    $updateStmt = $pdo->prepare("
        UPDATE employees SET
            branch_id = ?, department_id = ?, first_name = ?, last_name = ?, email = ?,
            phone = ?, role_title = ?, employment_type = ?, shift_preference = ?,
            hourly_rate = ?, max_weekly_hours = ?, skills = ?, status = ?, emergency_contact = ?
        WHERE id = ?
    ");
    $updateStmt->execute([
        $branchId, $deptId, $firstName, $lastName, $email,
        $phone, $roleTitle, $empType, $shiftPref,
        $rate, $maxHours, $skills, $status, $emergencyContact, $id
    ]);

    logAudit($pdo, (int)$user['id'], 'EMPLOYEE_UPDATE', 'employees', $id, [
        'name' => "$firstName $lastName",
        'status' => $status
    ], $branchId);

    jsonResponse(['success' => true, 'message' => 'Employee profile updated successfully']);
}

function toggleStatus(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized action'], 403);
    }

    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, status, first_name, last_name FROM employees WHERE id = ?");
    $stmt->execute([$id]);
    $emp = $stmt->fetch();
    if (!$emp) {
        jsonResponse(['success' => false, 'message' => 'Employee not found'], 404);
    }

    $newStatus = $emp['status'] === 'active' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE employees SET status = ? WHERE id = ?")->execute([$newStatus, $id]);

    logAudit($pdo, (int)$user['id'], 'EMPLOYEE_STATUS_TOGGLE', 'employees', $id, [
        'from' => $emp['status'],
        'to' => $newStatus
    ]);

    jsonResponse([
        'success' => true,
        'message' => "Employee status changed to $newStatus",
        'new_status' => $newStatus
    ]);
}

function deleteEmployee(PDO $pdo, array $user): void {
    if ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Only administrators can delete employee records'], 403);
    }

    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);

    // Soft deactivate instead of hard delete to preserve historical reporting & audits
    $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE users SET status = 'inactive' WHERE employee_id = ?")->execute([$id]);

    logAudit($pdo, (int)$user['id'], 'EMPLOYEE_DEACTIVATE', 'employees', $id);

    jsonResponse(['success' => true, 'message' => 'Employee deactivated successfully']);
}

function getAvailableEmployeesForShift(PDO $pdo): void {
    $shiftDate = $_GET['shift_date'] ?? date('Y-m-d');
    $startTime = $_GET['start_time'] ?? '06:00:00';
    $endTime = $_GET['end_time'] ?? '14:00:00';
    $isOvernight = !empty($_GET['is_overnight']) && $_GET['is_overnight'] !== '0';

    $dates = getShiftDatetimes($shiftDate, $startTime, $endTime, $isOvernight);
    $startDt = $dates['start'];
    $endDt = $dates['end'];

    // Find active employees who DO NOT have an overlapping shift
    $sql = "
        SELECT e.id, e.employee_code, e.first_name, e.last_name, e.role_title, e.shift_preference, e.branch_id, b.name as branch_name
        FROM employees e
        JOIN branches b ON e.branch_id = b.id
        WHERE e.status = 'active'
        AND e.id NOT IN (
            SELECT sa.employee_id
            FROM shift_assignments sa
            WHERE sa.status NOT IN ('cancelled')
            AND (
                (sa.start_datetime < ? AND sa.end_datetime > ?) OR
                (sa.start_datetime >= ? AND sa.start_datetime < ?)
            )
        )
        ORDER BY e.first_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$endDt, $startDt, $startDt, $endDt]);
    $available = $stmt->fetchAll();

    jsonResponse(['success' => true, 'available_employees' => $available, 'count' => count($available)]);
}
