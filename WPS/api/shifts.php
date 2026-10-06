<?php
/**
 * 24/7 - Shift Scheduling & Roster Management API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'templates':
        handleTemplates($pdo, $method, $user);
        break;

    case 'create_assignment':
        createAssignment($pdo, $user);
        break;

    case 'batch_schedule':
        batchSchedule($pdo, $user);
        break;

    case 'update_assignment':
        updateAssignment($pdo, $user);
        break;

    case 'delete_assignment':
        deleteAssignment($pdo, $user);
        break;

    case 'swaps':
        handleSwaps($pdo, $method, $user);
        break;

    case 'roster_view':
        getRosterView($pdo);
        break;

    default:
        // Default GET: list assignments with filters
        if ($method === 'GET') {
            listAssignments($pdo);
        } else {
            jsonResponse(['success' => false, 'message' => 'Action required'], 400);
        }
}

function handleTemplates(PDO $pdo, string $method, array $user): void {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT * FROM shift_templates WHERE is_active = 1 ORDER BY start_time ASC");
        jsonResponse(['success' => true, 'templates' => $stmt->fetchAll()]);
    } elseif ($method === 'POST') {
        if (!in_array($user['role'], ['admin', 'manager'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized to modify shift templates'], 403);
        }
        $data = getRequestData();
        $name = trim($data['name'] ?? '');
        $code = trim($data['shift_code'] ?? '');
        $startTime = trim($data['start_time'] ?? '');
        $endTime = trim($data['end_time'] ?? '');
        $color = trim($data['color'] ?? '#3b82f6');
        $minStaff = (int)($data['min_staff_required'] ?? 3);
        $description = trim($data['description'] ?? '');

        if (empty($name) || empty($code) || empty($startTime) || empty($endTime)) {
            jsonResponse(['success' => false, 'message' => 'Name, Code, Start Time and End Time are required'], 422);
        }

        // Automatic overnight detection
        $isOvernight = !empty($data['is_overnight']) || (strtotime("1970-01-01 $endTime") <= strtotime("1970-01-01 $startTime"));
        $duration = calculateShiftDuration($startTime, $endTime, $isOvernight);

        if (isset($data['id']) && (int)$data['id'] > 0) {
            $id = (int)$data['id'];
            $stmt = $pdo->prepare("
                UPDATE shift_templates SET
                    name = ?, shift_code = ?, start_time = ?, end_time = ?,
                    is_overnight = ?, duration_hours = ?, color = ?, min_staff_required = ?, description = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $code, $startTime, $endTime, $isOvernight ? 1 : 0, $duration, $color, $minStaff, $description, $id]);
            logAudit($pdo, (int)$user['id'], 'SHIFT_TEMPLATE_UPDATE', 'shift_templates', $id);
            jsonResponse(['success' => true, 'message' => 'Shift template updated successfully']);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO shift_templates (name, shift_code, start_time, end_time, is_overnight, duration_hours, color, min_staff_required, description, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$name, $code, $startTime, $endTime, $isOvernight ? 1 : 0, $duration, $color, $minStaff, $description]);
            $newId = (int)$pdo->lastInsertId();
            logAudit($pdo, (int)$user['id'], 'SHIFT_TEMPLATE_CREATE', 'shift_templates', $newId);
            jsonResponse(['success' => true, 'message' => 'Shift template created successfully', 'template_id' => $newId]);
        }
    }
}

function listAssignments(PDO $pdo): void {
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-3 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
    $shiftTemplateId = isset($_GET['shift_template_id']) && $_GET['shift_template_id'] !== 'all' ? (int)$_GET['shift_template_id'] : null;
    $employeeId = isset($_GET['employee_id']) && $_GET['employee_id'] !== 'all' ? (int)$_GET['employee_id'] : null;
    $status = trim($_GET['status'] ?? '');

    $sql = "
        SELECT sa.*, 
               e.first_name, e.last_name, e.employee_code, e.role_title, e.avatar_color, e.shift_preference,
               st.name as shift_name, st.shift_code, st.start_time, st.end_time, st.is_overnight, st.duration_hours, st.color as shift_color,
               b.name as branch_name, b.code as branch_code,
               (SELECT a.id FROM attendance a WHERE a.shift_assignment_id = sa.id LIMIT 1) as attendance_id,
               (SELECT a.status FROM attendance a WHERE a.shift_assignment_id = sa.id LIMIT 1) as attendance_status,
               (SELECT a.clock_in FROM attendance a WHERE a.shift_assignment_id = sa.id LIMIT 1) as clock_in,
               (SELECT a.clock_out FROM attendance a WHERE a.shift_assignment_id = sa.id LIMIT 1) as clock_out
        FROM shift_assignments sa
        JOIN employees e ON sa.employee_id = e.id
        JOIN shift_templates st ON sa.shift_template_id = st.id
        JOIN branches b ON sa.branch_id = b.id
        WHERE sa.shift_date BETWEEN ? AND ?
    ";

    $params = [$startDate, $endDate];

    if ($branchId !== null) {
        $sql .= " AND sa.branch_id = ?";
        $params[] = $branchId;
    }
    if ($shiftTemplateId !== null) {
        $sql .= " AND sa.shift_template_id = ?";
        $params[] = $shiftTemplateId;
    }
    if ($employeeId !== null) {
        $sql .= " AND sa.employee_id = ?";
        $params[] = $employeeId;
    }
    if (!empty($status) && $status !== 'all') {
        $sql .= " AND sa.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY sa.shift_date ASC, st.start_time ASC, e.first_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll();

    jsonResponse(['success' => true, 'assignments' => $assignments, 'count' => count($assignments)]);
}

function createAssignment(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to schedule shifts'], 403);
    }

    $data = getRequestData();
    $branchId = (int)($data['branch_id'] ?? 1);
    $shiftTemplateId = (int)($data['shift_template_id'] ?? 0);
    $employeeId = (int)($data['employee_id'] ?? 0);
    $shiftDate = trim($data['shift_date'] ?? date('Y-m-d'));
    $notes = trim($data['notes'] ?? '');

    if (!$shiftTemplateId || !$employeeId || empty($shiftDate)) {
        jsonResponse(['success' => false, 'message' => 'Template, Employee, and Shift Date are required'], 422);
    }

    // Get shift template details
    $tmplStmt = $pdo->prepare("SELECT * FROM shift_templates WHERE id = ?");
    $tmplStmt->execute([$shiftTemplateId]);
    $tmpl = $tmplStmt->fetch();
    if (!$tmpl) {
        jsonResponse(['success' => false, 'message' => 'Invalid shift template'], 404);
    }

    // Check Employee Status
    $empStmt = $pdo->prepare("SELECT id, first_name, last_name, status FROM employees WHERE id = ?");
    $empStmt->execute([$employeeId]);
    $emp = $empStmt->fetch();
    if (!$emp || $emp['status'] !== 'active') {
        jsonResponse(['success' => false, 'message' => 'Cannot schedule an inactive or non-existent employee'], 422);
    }

    // Calculate Exact Start and End Datetime (Properly handling overnight shifts!)
    $datetimes = getShiftDatetimes($shiftDate, $tmpl['start_time'], $tmpl['end_time'], (bool)$tmpl['is_overnight']);
    $startDt = $datetimes['start'];
    $endDt = $datetimes['end'];

    // CONFLICT DETECTION: Ensure employee doesn't already have an overlapping shift
    $conflictStmt = $pdo->prepare("
        SELECT sa.id, st.name as conflicting_shift, sa.start_datetime, sa.end_datetime
        FROM shift_assignments sa
        JOIN shift_templates st ON sa.shift_template_id = st.id
        WHERE sa.employee_id = ?
        AND sa.status NOT IN ('cancelled')
        AND (
            (sa.start_datetime < ? AND sa.end_datetime > ?) OR
            (sa.start_datetime >= ? AND sa.start_datetime < ?)
        )
        LIMIT 1
    ");
    $conflictStmt->execute([$employeeId, $endDt, $startDt, $startDt, $endDt]);
    $conflict = $conflictStmt->fetch();

    if ($conflict) {
        jsonResponse([
            'success' => false,
            'message' => "Schedule Conflict: {$emp['first_name']} {$emp['last_name']} is already scheduled for '{$conflict['conflicting_shift']}' ({$conflict['start_datetime']} to {$conflict['end_datetime']}). Double-booking is prevented in continuous operations.",
            'conflict' => $conflict
        ], 409);
    }

    // Insert Assignment
    $insertStmt = $pdo->prepare("
        INSERT INTO shift_assignments (branch_id, shift_template_id, employee_id, shift_date, start_datetime, end_datetime, status, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, ?)
    ");
    $insertStmt->execute([$branchId, $shiftTemplateId, $employeeId, $shiftDate, $startDt, $endDt, $notes, (int)$user['id']]);
    $assignId = (int)$pdo->lastInsertId();

    logAudit($pdo, (int)$user['id'], 'SHIFT_ASSIGN', 'shift_assignments', $assignId, [
        'employee' => "{$emp['first_name']} {$emp['last_name']}",
        'shift' => $tmpl['name'],
        'date' => $shiftDate,
        'hours' => "$startDt -> $endDt"
    ], $branchId);

    jsonResponse([
        'success' => true,
        'message' => "Successfully scheduled {$emp['first_name']} {$emp['last_name']} for {$tmpl['name']} on $shiftDate ($startDt to $endDt).",
        'assignment_id' => $assignId
    ]);
}

function batchSchedule(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized for batch scheduling'], 403);
    }

    $data = getRequestData();
    $branchId = (int)($data['branch_id'] ?? 1);
    $shiftTemplateId = (int)($data['shift_template_id'] ?? 0);
    $employeeIds = $data['employee_ids'] ?? [];
    $dates = $data['dates'] ?? [];

    if (!$shiftTemplateId || empty($employeeIds) || empty($dates)) {
        jsonResponse(['success' => false, 'message' => 'Template, employees list, and dates list required'], 422);
    }

    $tmplStmt = $pdo->prepare("SELECT * FROM shift_templates WHERE id = ?");
    $tmplStmt->execute([$shiftTemplateId]);
    $tmpl = $tmplStmt->fetch();
    if (!$tmpl) {
        jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
    }

    $created = 0;
    $skipped = 0;

    foreach ($dates as $date) {
        $dt = getShiftDatetimes($date, $tmpl['start_time'], $tmpl['end_time'], (bool)$tmpl['is_overnight']);
        foreach ($employeeIds as $empId) {
            $empId = (int)$empId;

            // Check conflict
            $chk = $pdo->prepare("
                SELECT id FROM shift_assignments
                WHERE employee_id = ? AND status NOT IN ('cancelled')
                AND ((start_datetime < ? AND end_datetime > ?) OR (start_datetime >= ? AND start_datetime < ?))
            ");
            $chk->execute([$empId, $dt['end'], $dt['start'], $dt['start'], $dt['end']]);
            if ($chk->fetch()) {
                $skipped++;
                continue;
            }

            $ins = $pdo->prepare("
                INSERT INTO shift_assignments (branch_id, shift_template_id, employee_id, shift_date, start_datetime, end_datetime, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)
            ");
            $ins->execute([$branchId, $shiftTemplateId, $empId, $date, $dt['start'], $dt['end'], (int)$user['id']]);
            $created++;
        }
    }

    logAudit($pdo, (int)$user['id'], 'SHIFT_BATCH_SCHEDULE', 'shift_assignments', null, [
        'created_count' => $created,
        'conflicts_skipped' => $skipped
    ], $branchId);

    jsonResponse([
        'success' => true,
        'message' => "Batch scheduling complete: $created shifts created ($skipped skipped due to existing schedule conflicts).",
        'created' => $created,
        'skipped' => $skipped
    ]);
}

function updateAssignment(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to update shift'], 403);
    }

    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);
    $status = $data['status'] ?? 'scheduled';
    $notes = trim($data['notes'] ?? '');

    $stmt = $pdo->prepare("UPDATE shift_assignments SET status = ?, notes = ? WHERE id = ?");
    $stmt->execute([$status, $notes, $id]);

    logAudit($pdo, (int)$user['id'], 'SHIFT_UPDATE_STATUS', 'shift_assignments', $id, ['new_status' => $status]);

    jsonResponse(['success' => true, 'message' => 'Shift assignment updated successfully']);
}

function deleteAssignment(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to delete shift'], 403);
    }

    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);

    // Check if attendance is already linked
    $chk = $pdo->prepare("SELECT id FROM attendance WHERE shift_assignment_id = ?");
    $chk->execute([$id]);
    if ($chk->fetch()) {
        // Soft cancel instead of hard delete
        $pdo->prepare("UPDATE shift_assignments SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Shift marked as cancelled because attendance records exist for it.']);
    } else {
        $pdo->prepare("DELETE FROM shift_assignments WHERE id = ?")->execute([$id]);
        logAudit($pdo, (int)$user['id'], 'SHIFT_DELETE', 'shift_assignments', $id);
        jsonResponse(['success' => true, 'message' => 'Shift assignment removed successfully']);
    }
}

function handleSwaps(PDO $pdo, string $method, array $user): void {
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT ss.*, 
                   req.first_name as req_first, req.last_name as req_last, req.employee_code as req_code,
                   tar.first_name as tar_first, tar.last_name as tar_last, tar.employee_code as tar_code,
                   sa.shift_date, sa.start_datetime, sa.end_datetime,
                   st.name as shift_name, st.color as shift_color
            FROM shift_swaps ss
            JOIN shift_assignments sa ON ss.shift_assignment_id = sa.id
            JOIN shift_templates st ON sa.shift_template_id = st.id
            JOIN employees req ON ss.requester_id = req.id
            JOIN employees tar ON ss.target_employee_id = tar.id
            ORDER BY ss.requested_at DESC
        ");
        jsonResponse(['success' => true, 'swaps' => $stmt->fetchAll()]);
    } elseif ($method === 'POST') {
        $data = getRequestData();
        $subAction = $data['sub_action'] ?? 'request';

        if ($subAction === 'request') {
            $shiftAssignId = (int)($data['shift_assignment_id'] ?? 0);
            $targetEmpId = (int)($data['target_employee_id'] ?? 0);
            $reason = trim($data['reason'] ?? '');

            // Get requester employee
            $assignStmt = $pdo->prepare("SELECT employee_id FROM shift_assignments WHERE id = ?");
            $assignStmt->execute([$shiftAssignId]);
            $assign = $assignStmt->fetch();
            if (!$assign) {
                jsonResponse(['success' => false, 'message' => 'Invalid shift assignment'], 404);
            }

            $ins = $pdo->prepare("
                INSERT INTO shift_swaps (shift_assignment_id, requester_id, target_employee_id, status, reason)
                VALUES (?, ?, ?, 'pending', ?)
            ");
            $ins->execute([$shiftAssignId, $assign['employee_id'], $targetEmpId, $reason]);
            jsonResponse(['success' => true, 'message' => 'Shift swap request submitted for supervisor approval']);
        } elseif ($subAction === 'review') {
            if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized to review shift swaps'], 403);
            }
            $swapId = (int)($data['swap_id'] ?? 0);
            $status = in_array($data['status'] ?? '', ['approved', 'rejected'], true) ? $data['status'] : 'rejected';

            $swapStmt = $pdo->prepare("SELECT * FROM shift_swaps WHERE id = ?");
            $swapStmt->execute([$swapId]);
            $swap = $swapStmt->fetch();
            if (!$swap) {
                jsonResponse(['success' => false, 'message' => 'Swap request not found'], 404);
            }

            $pdo->prepare("
                UPDATE shift_swaps SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?
            ")->execute([$status, (int)$user['id'], $swapId]);

            // If approved, transfer shift assignment to target employee
            if ($status === 'approved') {
                $pdo->prepare("
                    UPDATE shift_assignments SET employee_id = ?, status = 'swapped' WHERE id = ?
                ")->execute([$swap['target_employee_id'], $swap['shift_assignment_id']]);
            }

            jsonResponse(['success' => true, 'message' => "Shift swap $status successfully"]);
        }
    }
}

function getRosterView(PDO $pdo): void {
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-2 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+4 days'));
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;

    // Fetch shift templates
    $templates = $pdo->query("SELECT * FROM shift_templates WHERE is_active = 1 ORDER BY start_time ASC")->fetchAll();

    // Fetch roster slots
    $sql = "
        SELECT sa.*, 
               e.first_name, e.last_name, e.employee_code, e.role_title, e.avatar_color,
               st.name as shift_name, st.shift_code, st.start_time, st.end_time, st.is_overnight, st.color as shift_color,
               (SELECT a.status FROM attendance a WHERE a.shift_assignment_id = sa.id LIMIT 1) as live_attendance_status
        FROM shift_assignments sa
        JOIN employees e ON sa.employee_id = e.id
        JOIN shift_templates st ON sa.shift_template_id = st.id
        WHERE sa.shift_date BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    if ($branchId !== null) {
        $sql .= " AND sa.branch_id = ?";
        $params[] = $branchId;
    }
    $sql .= " ORDER BY sa.shift_date ASC, st.start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'templates' => $templates,
        'assignments' => $assignments,
        'date_range' => ['start' => $startDate, 'end' => $endDate]
    ]);
}
