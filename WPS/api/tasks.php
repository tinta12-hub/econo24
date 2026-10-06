<?php
/**
 * 24/7 - Task Management & Shift Operations API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'details':
        getTaskDetails($pdo);
        break;

    case 'create':
        createTask($pdo, $user);
        break;

    case 'update':
        updateTask($pdo, $user);
        break;

    case 'update_status':
        updateTaskStatus($pdo, $user);
        break;

    case 'toggle_checklist':
        toggleChecklistItem($pdo, $user);
        break;

    case 'delete':
        deleteTask($pdo, $user);
        break;

    default:
        if ($method === 'GET') {
            listTasks($pdo);
        } else {
            jsonResponse(['success' => false, 'message' => 'Action required'], 400);
        }
}

function listTasks(PDO $pdo): void {
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
    $shiftTemplateId = isset($_GET['shift_template_id']) && $_GET['shift_template_id'] !== 'all' ? (int)$_GET['shift_template_id'] : null;
    $status = trim($_GET['status'] ?? '');
    $priority = trim($_GET['priority'] ?? '');
    $employeeId = isset($_GET['employee_id']) && $_GET['employee_id'] !== 'all' ? (int)$_GET['employee_id'] : null;
    $isHandover = isset($_GET['is_handover']) && $_GET['is_handover'] !== '' ? (int)$_GET['is_handover'] : null;
    $date = trim($_GET['shift_date'] ?? '');

    $sql = "
        SELECT t.*, 
               e.first_name, e.last_name, e.employee_code, e.avatar_color, e.role_title,
               comp_e.first_name as comp_first, comp_e.last_name as comp_last,
               b.name as branch_name, d.name as department_name,
               st.name as shift_name, st.color as shift_color,
               (SELECT COUNT(*) FROM task_checklists WHERE task_id = t.id) as checklist_total,
               (SELECT COUNT(*) FROM task_checklists WHERE task_id = t.id AND is_completed = 1) as checklist_done,
               CASE WHEN t.status != 'completed' AND t.due_datetime < NOW() THEN 1 ELSE 0 END as is_overdue
        FROM tasks t
        JOIN branches b ON t.branch_id = b.id
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN employees e ON t.assigned_to_employee_id = e.id
        LEFT JOIN employees comp_e ON t.completed_by = comp_e.id
        LEFT JOIN shift_templates st ON t.shift_template_id = st.id
        WHERE 1=1
    ";

    $params = [];
    if ($branchId !== null) {
        $sql .= " AND t.branch_id = ?";
        $params[] = $branchId;
    }
    if ($shiftTemplateId !== null) {
        $sql .= " AND t.shift_template_id = ?";
        $params[] = $shiftTemplateId;
    }
    if (!empty($status) && $status !== 'all') {
        $sql .= " AND t.status = ?";
        $params[] = $status;
    }
    if (!empty($priority) && $priority !== 'all') {
        $sql .= " AND t.priority = ?";
        $params[] = $priority;
    }
    if ($employeeId !== null) {
        $sql .= " AND t.assigned_to_employee_id = ?";
        $params[] = $employeeId;
    }
    if ($isHandover !== null) {
        $sql .= " AND t.is_handover_task = ?";
        $params[] = $isHandover;
    }
    if (!empty($date)) {
        $sql .= " AND t.shift_date = ?";
        $params[] = $date;
    }

    $sql .= " ORDER BY FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.due_datetime ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();

    jsonResponse(['success' => true, 'tasks' => $tasks, 'count' => count($tasks)]);
}

function getTaskDetails(PDO $pdo): void {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT t.*, 
               e.first_name, e.last_name, e.employee_code, e.avatar_color, e.role_title,
               b.name as branch_name, d.name as department_name, st.name as shift_name
        FROM tasks t
        JOIN branches b ON t.branch_id = b.id
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN employees e ON t.assigned_to_employee_id = e.id
        LEFT JOIN shift_templates st ON t.shift_template_id = st.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if (!$task) {
        jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
    }

    // Checklists
    $chkStmt = $pdo->prepare("SELECT * FROM task_checklists WHERE task_id = ? ORDER BY sort_order ASC, id ASC");
    $chkStmt->execute([$id]);
    $task['checklists'] = $chkStmt->fetchAll();

    jsonResponse(['success' => true, 'task' => $task]);
}

function createTask(PDO $pdo, array $user): void {
    $data = getRequestData();
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '');
    $branchId = (int)($data['branch_id'] ?? 1);
    $deptId = !empty($data['department_id']) ? (int)$data['department_id'] : null;
    $shiftTemplateId = !empty($data['shift_template_id']) ? (int)$data['shift_template_id'] : null;
    $assignedTo = !empty($data['assigned_to_employee_id']) ? (int)$data['assigned_to_employee_id'] : null;
    $priority = in_array($data['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $data['priority'] : 'medium';
    $shiftDate = !empty($data['shift_date']) ? $data['shift_date'] : date('Y-m-d');
    $dueDatetime = !empty($data['due_datetime']) ? $data['due_datetime'] : "$shiftDate 23:59:59";
    $isHandover = !empty($data['is_handover_task']) ? 1 : 0;
    $handoverNotes = trim($data['handover_notes'] ?? '');
    $checklists = $data['checklists'] ?? [];

    if (empty($title)) {
        jsonResponse(['success' => false, 'message' => 'Task title is required'], 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO tasks (branch_id, department_id, shift_template_id, title, description, priority, status, assigned_to_employee_id, shift_date, due_datetime, is_handover_task, handover_notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $branchId, $deptId, $shiftTemplateId, $title, $description, $priority,
        $assignedTo, $shiftDate, $dueDatetime, $isHandover, $handoverNotes, (int)$user['id']
    ]);
    $taskId = (int)$pdo->lastInsertId();

    // Insert Checklists
    if (is_array($checklists)) {
        $chkStmt = $pdo->prepare("INSERT INTO task_checklists (task_id, item_text, is_completed, sort_order) VALUES (?, ?, 0, ?)");
        $order = 1;
        foreach ($checklists as $item) {
            $itemText = is_string($item) ? trim($item) : trim($item['text'] ?? '');
            if (!empty($itemText)) {
                $chkStmt->execute([$taskId, $itemText, $order++]);
            }
        }
    }

    logAudit($pdo, (int)$user['id'], 'TASK_CREATE', 'tasks', $taskId, ['title' => $title, 'priority' => $priority], $branchId);

    if ($priority === 'urgent' && $assignedTo) {
        createNotification(
            $pdo,
            'Urgent Operational Task Assigned',
            "You have been assigned urgent task: '$title'. Due by " . date('H:i', strtotime($dueDatetime)),
            'warning',
            $branchId,
            'tasks',
            $taskId
        );
    }

    jsonResponse(['success' => true, 'message' => "Task '$title' created successfully", 'task_id' => $taskId]);
}

function updateTaskStatus(PDO $pdo, array $user): void {
    $data = getRequestData();
    $taskId = (int)($data['task_id'] ?? $data['id'] ?? 0);
    $status = in_array($data['status'] ?? '', ['pending', 'in_progress', 'under_review', 'completed', 'escalated'], true) ? $data['status'] : 'in_progress';

    $taskStmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $taskStmt->execute([$taskId]);
    $task = $taskStmt->fetch();
    if (!$task) {
        jsonResponse(['success' => false, 'message' => 'Task not found'], 404);
    }

    $completedAt = ($status === 'completed') ? date('Y-m-d H:i:s') : null;
    $completedBy = ($status === 'completed') ? ($user['employee_id'] ?: 1) : null;

    $upd = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = ?, completed_by = ? WHERE id = ?");
    $upd->execute([$status, $completedAt, $completedBy, $taskId]);

    // If completed, also complete all checklist items
    if ($status === 'completed') {
        $pdo->prepare("UPDATE task_checklists SET is_completed = 1, completed_at = NOW() WHERE task_id = ? AND is_completed = 0")->execute([$taskId]);
    }

    logAudit($pdo, (int)$user['id'], 'TASK_STATUS_UPDATE', 'tasks', $taskId, [
        'from' => $task['status'],
        'to' => $status
    ], (int)$task['branch_id']);

    jsonResponse(['success' => true, 'message' => "Task status updated to $status", 'status' => $status]);
}

function toggleChecklistItem(PDO $pdo, array $user): void {
    $data = getRequestData();
    $itemId = (int)($data['item_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM task_checklists WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if (!$item) {
        jsonResponse(['success' => false, 'message' => 'Checklist item not found'], 404);
    }

    $newVal = $item['is_completed'] ? 0 : 1;
    $completedAt = $newVal ? date('Y-m-d H:i:s') : null;

    $pdo->prepare("UPDATE task_checklists SET is_completed = ?, completed_at = ? WHERE id = ?")
        ->execute([$newVal, $completedAt, $itemId]);

    jsonResponse(['success' => true, 'is_completed' => (bool)$newVal]);
}

function updateTask(PDO $pdo, array $user): void {
    $data = getRequestData();
    $taskId = (int)($data['id'] ?? 0);
    $title = trim($data['title'] ?? '');
    $description = trim($data['description'] ?? '');
    $priority = in_array($data['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $data['priority'] : 'medium';
    $assignedTo = !empty($data['assigned_to_employee_id']) ? (int)$data['assigned_to_employee_id'] : null;
    $dueDatetime = trim($data['due_datetime'] ?? '');
    $isHandover = !empty($data['is_handover_task']) ? 1 : 0;
    $handoverNotes = trim($data['handover_notes'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE tasks SET
            title = ?, description = ?, priority = ?, assigned_to_employee_id = ?,
            due_datetime = ?, is_handover_task = ?, handover_notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$title, $description, $priority, $assignedTo, $dueDatetime, $isHandover, $handoverNotes, $taskId]);

    logAudit($pdo, (int)$user['id'], 'TASK_UPDATE', 'tasks', $taskId);

    jsonResponse(['success' => true, 'message' => 'Task updated successfully']);
}

function deleteTask(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to delete task'], 403);
    }

    $data = getRequestData();
    $taskId = (int)($data['id'] ?? 0);

    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
    logAudit($pdo, (int)$user['id'], 'TASK_DELETE', 'tasks', $taskId);

    jsonResponse(['success' => true, 'message' => 'Task deleted successfully']);
}
