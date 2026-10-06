<?php
/**
 * 24/7 - Branch & Department Management API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'GET' ? 'list' : '');

switch ($action) {
    case 'list':
        listBranches($pdo);
        break;

    case 'departments':
        listDepartments($pdo);
        break;

    case 'create_branch':
        createBranch($pdo, $user);
        break;

    case 'create_department':
        createDepartment($pdo, $user);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

function listBranches(PDO $pdo): void {
    $stmt = $pdo->query("
        SELECT b.*, 
               (SELECT COUNT(*) FROM employees WHERE branch_id = b.id AND status = 'active') as active_employees_count,
               (SELECT COUNT(*) FROM attendance WHERE branch_id = b.id AND clock_out IS NULL) as live_on_duty_count
        FROM branches b
        ORDER BY b.id ASC
    ");
    $branches = $stmt->fetchAll();
    jsonResponse(['success' => true, 'branches' => $branches]);
}

function listDepartments(PDO $pdo): void {
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
    $sql = "SELECT d.*, b.name as branch_name FROM departments d LEFT JOIN branches b ON d.branch_id = b.id";
    if ($branchId !== null) {
        $sql .= " WHERE d.branch_id = ? OR d.branch_id IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$branchId]);
    } else {
        $stmt = $pdo->query($sql);
    }
    jsonResponse(['success' => true, 'departments' => $stmt->fetchAll()]);
}

function createBranch(PDO $pdo, array $user): void {
    if ($user['role'] !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Only administrators can create branches'], 403);
    }
    $data = getRequestData();
    $name = trim($data['name'] ?? '');
    $code = trim($data['code'] ?? '');
    $address = trim($data['address'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $managerName = trim($data['manager_name'] ?? '');

    if (empty($name) || empty($code)) {
        jsonResponse(['success' => false, 'message' => 'Branch name and code are required'], 422);
    }

    $ins = $pdo->prepare("INSERT INTO branches (name, code, address, phone, manager_name, is_24hr) VALUES (?, ?, ?, ?, ?, 1)");
    $ins->execute([$name, $code, $address, $phone, $managerName]);
    $newId = (int)$pdo->lastInsertId();

    logAudit($pdo, (int)$user['id'], 'BRANCH_CREATE', 'branches', $newId, ['name' => $name, 'code' => $code]);

    jsonResponse(['success' => true, 'message' => "Branch '$name' created successfully", 'branch_id' => $newId]);
}

function createDepartment(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
    }
    $data = getRequestData();
    $branchId = !empty($data['branch_id']) ? (int)$data['branch_id'] : null;
    $name = trim($data['name'] ?? '');
    $code = trim($data['code'] ?? '');
    $desc = trim($data['description'] ?? '');

    if (empty($name) || empty($code)) {
        jsonResponse(['success' => false, 'message' => 'Department name and code are required'], 422);
    }

    $ins = $pdo->prepare("INSERT INTO departments (branch_id, name, code, description) VALUES (?, ?, ?, ?)");
    $ins->execute([$branchId, $name, $code, $desc]);
    $deptId = (int)$pdo->lastInsertId();

    jsonResponse(['success' => true, 'message' => "Department '$name' created successfully", 'department_id' => $deptId]);
}
