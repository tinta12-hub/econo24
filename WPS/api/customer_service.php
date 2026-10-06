<?php
/**
 * 24/7 - Customer Service & Incident Logging API
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
        getTicketDetails($pdo);
        break;

    case 'create':
        createTicket($pdo, $user);
        break;

    case 'update':
        updateTicket($pdo, $user);
        break;

    case 'resolve':
        resolveTicket($pdo, $user);
        break;

    case 'flag_handover':
        flagHandover($pdo, $user);
        break;

    case 'delete':
        deleteTicket($pdo, $user);
        break;

    default:
        if ($method === 'GET') {
            listTickets($pdo);
        } else {
            jsonResponse(['success' => false, 'message' => 'Action required'], 400);
        }
}

function listTickets(PDO $pdo): void {
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
    $status = trim($_GET['status'] ?? '');
    $severity = trim($_GET['severity'] ?? '');
    $channel = trim($_GET['channel'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $isHandover = isset($_GET['is_handover_flagged']) && $_GET['is_handover_flagged'] !== '' ? (int)$_GET['is_handover_flagged'] : null;

    $sql = "
        SELECT cs.*, 
               log_e.first_name as log_first, log_e.last_name as log_last, log_e.employee_code as log_code,
               ass_e.first_name as ass_first, ass_e.last_name as ass_last,
               b.name as branch_name, d.name as department_name, st.name as shift_name
        FROM customer_service_logs cs
        JOIN branches b ON cs.branch_id = b.id
        LEFT JOIN departments d ON cs.department_id = d.id
        LEFT JOIN shift_templates st ON cs.shift_template_id = st.id
        JOIN employees log_e ON cs.logged_by_employee_id = log_e.id
        LEFT JOIN employees ass_e ON cs.assigned_to_employee_id = ass_e.id
        WHERE 1=1
    ";

    $params = [];
    if ($branchId !== null) {
        $sql .= " AND cs.branch_id = ?";
        $params[] = $branchId;
    }
    if (!empty($status) && $status !== 'all') {
        $sql .= " AND cs.status = ?";
        $params[] = $status;
    }
    if (!empty($severity) && $severity !== 'all') {
        $sql .= " AND cs.severity = ?";
        $params[] = $severity;
    }
    if (!empty($channel) && $channel !== 'all') {
        $sql .= " AND cs.channel = ?";
        $params[] = $channel;
    }
    if ($isHandover !== null) {
        $sql .= " AND cs.is_handover_flagged = ?";
        $params[] = $isHandover;
    }
    if (!empty($search)) {
        $sql .= " AND (cs.ticket_number LIKE ? OR cs.customer_name LIKE ? OR cs.subject LIKE ? OR cs.description LIKE ?)";
        $wildcard = "%$search%";
        $params = array_merge($params, [$wildcard, $wildcard, $wildcard, $wildcard]);
    }

    $sql .= " ORDER BY FIELD(cs.severity, 'critical_247', 'high', 'medium', 'low'), cs.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();

    jsonResponse(['success' => true, 'tickets' => $tickets, 'count' => count($tickets)]);
}

function getTicketDetails(PDO $pdo): void {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT cs.*, 
               log_e.first_name as log_first, log_e.last_name as log_last, log_e.employee_code as log_code, log_e.role_title as log_role,
               ass_e.first_name as ass_first, ass_e.last_name as ass_last,
               b.name as branch_name, d.name as department_name, st.name as shift_name
        FROM customer_service_logs cs
        JOIN branches b ON cs.branch_id = b.id
        LEFT JOIN departments d ON cs.department_id = d.id
        LEFT JOIN shift_templates st ON cs.shift_template_id = st.id
        JOIN employees log_e ON cs.logged_by_employee_id = log_e.id
        LEFT JOIN employees ass_e ON cs.assigned_to_employee_id = ass_e.id
        WHERE cs.id = ?
    ");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        jsonResponse(['success' => false, 'message' => 'Ticket not found'], 404);
    }
    jsonResponse(['success' => true, 'ticket' => $ticket]);
}

function createTicket(PDO $pdo, array $user): void {
    $data = getRequestData();
    $customerName = trim($data['customer_name'] ?? '');
    $customerContact = trim($data['customer_contact'] ?? '');
    $channel = in_array($data['channel'] ?? '', ['phone', 'walk_in', 'emergency_line', 'email', 'chat', 'radio'], true) ? $data['channel'] : 'phone';
    $category = in_array($data['category'] ?? '', ['inquiry', 'complaint', 'incident', 'urgent_request', 'maintenance_call', 'dispatch_issue'], true) ? $data['category'] : 'inquiry';
    $severity = in_array($data['severity'] ?? '', ['low', 'medium', 'high', 'critical_247'], true) ? $data['severity'] : 'low';
    $subject = trim($data['subject'] ?? '');
    $description = trim($data['description'] ?? '');
    $actionTaken = trim($data['action_taken'] ?? '');
    $branchId = (int)($data['branch_id'] ?? 1);
    $deptId = !empty($data['department_id']) ? (int)$data['department_id'] : null;
    $shiftTemplateId = !empty($data['shift_template_id']) ? (int)$data['shift_template_id'] : null;
    $assignedTo = !empty($data['assigned_to_employee_id']) ? (int)$data['assigned_to_employee_id'] : null;
    $isHandover = !empty($data['is_handover_flagged']) ? 1 : 0;
    $status = in_array($data['status'] ?? '', ['open', 'in_progress', 'pending_handover', 'resolved'], true) ? $data['status'] : 'open';

    if (empty($customerName) || empty($subject)) {
        jsonResponse(['success' => false, 'message' => 'Customer name and subject are required'], 422);
    }

    // Determine logging employee
    $loggedByEmpId = $user['employee_id'] ?: 1;

    // Generate Ticket Number
    $count = $pdo->query("SELECT COUNT(*) FROM customer_service_logs")->fetchColumn();
    $ticketNum = sprintf('CS-247-%04d', ((int)$count) + 8806);

    $insStmt = $pdo->prepare("
        INSERT INTO customer_service_logs (
            branch_id, department_id, shift_template_id, logged_by_employee_id, assigned_to_employee_id,
            ticket_number, customer_name, customer_contact, channel, category, severity, status,
            subject, description, action_taken, is_handover_flagged, response_time_minutes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insStmt->execute([
        $branchId, $deptId, $shiftTemplateId, $loggedByEmpId, $assignedTo,
        $ticketNum, $customerName, $customerContact, $channel, $category, $severity, $status,
        $subject, $description, $actionTaken, $isHandover, ($channel === 'emergency_line' ? 2 : 5)
    ]);
    $ticketId = (int)$pdo->lastInsertId();

    logAudit($pdo, (int)$user['id'], 'CUSTOMER_TICKET_CREATE', 'customer_service_logs', $ticketId, [
        'ticket' => $ticketNum,
        'customer' => $customerName,
        'severity' => $severity
    ], $branchId);

    if ($severity === 'critical_247') {
        createNotification(
            $pdo,
            'Critical 24/7 Customer Incident Logged',
            "Incident #$ticketNum logged by {$user['username']}: '$subject'. Immediate action required.",
            'critical',
            $branchId,
            'customer_service',
            $ticketId
        );
    }

    jsonResponse([
        'success' => true,
        'message' => "Ticket $ticketNum created successfully",
        'ticket_id' => $ticketId,
        'ticket_number' => $ticketNum
    ]);
}

function updateTicket(PDO $pdo, array $user): void {
    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);
    $status = in_array($data['status'] ?? '', ['open', 'in_progress', 'pending_handover', 'resolved', 'escalated'], true) ? $data['status'] : 'in_progress';
    $severity = in_array($data['severity'] ?? '', ['low', 'medium', 'high', 'critical_247'], true) ? $data['severity'] : 'low';
    $actionTaken = trim($data['action_taken'] ?? '');
    $resolutionNotes = trim($data['resolution_notes'] ?? '');
    $isHandover = !empty($data['is_handover_flagged']) ? 1 : 0;
    $assignedTo = !empty($data['assigned_to_employee_id']) ? (int)$data['assigned_to_employee_id'] : null;

    $resolvedAt = ($status === 'resolved') ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("
        UPDATE customer_service_logs SET
            status = ?, severity = ?, action_taken = ?, resolution_notes = ?,
            is_handover_flagged = ?, assigned_to_employee_id = ?,
            resolved_at = COALESCE(?, resolved_at)
        WHERE id = ?
    ");
    $stmt->execute([$status, $severity, $actionTaken, $resolutionNotes, $isHandover, $assignedTo, $resolvedAt, $id]);

    logAudit($pdo, (int)$user['id'], 'CUSTOMER_TICKET_UPDATE', 'customer_service_logs', $id, ['status' => $status]);

    jsonResponse(['success' => true, 'message' => 'Ticket updated successfully']);
}

function resolveTicket(PDO $pdo, array $user): void {
    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);
    $resolutionNotes = trim($data['resolution_notes'] ?? '');
    $csat = !empty($data['satisfaction_rating']) ? (int)$data['satisfaction_rating'] : 5;
    $resMinutes = (int)($data['resolution_time_minutes'] ?? 25);

    if (empty($resolutionNotes)) {
        jsonResponse(['success' => false, 'message' => 'Resolution notes are required to resolve a ticket'], 422);
    }

    $stmt = $pdo->prepare("
        UPDATE customer_service_logs SET
            status = 'resolved', resolution_notes = ?, resolution_time_minutes = ?,
            satisfaction_rating = ?, resolved_at = NOW(), is_handover_flagged = 0
        WHERE id = ?
    ");
    $stmt->execute([$resolutionNotes, $resMinutes, $csat, $id]);

    logAudit($pdo, (int)$user['id'], 'CUSTOMER_TICKET_RESOLVE', 'customer_service_logs', $id);

    jsonResponse(['success' => true, 'message' => 'Ticket marked as resolved successfully']);
}

function flagHandover(PDO $pdo, array $user): void {
    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT is_handover_flagged, ticket_number FROM customer_service_logs WHERE id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        jsonResponse(['success' => false, 'message' => 'Ticket not found'], 404);
    }

    $newFlag = $ticket['is_handover_flagged'] ? 0 : 1;
    $pdo->prepare("UPDATE customer_service_logs SET is_handover_flagged = ?, status = CASE WHEN ? = 1 THEN 'pending_handover' ELSE status END WHERE id = ?")
        ->execute([$newFlag, $newFlag, $id]);

    jsonResponse([
        'success' => true,
        'message' => $newFlag ? "Ticket #{$ticket['ticket_number']} flagged for Shift Handover" : "Handover flag removed",
        'is_handover_flagged' => (bool)$newFlag
    ]);
}

function deleteTicket(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to delete ticket'], 403);
    }

    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM customer_service_logs WHERE id = ?")->execute([$id]);
    logAudit($pdo, (int)$user['id'], 'CUSTOMER_TICKET_DELETE', 'customer_service_logs', $id);

    jsonResponse(['success' => true, 'message' => 'Ticket deleted']);
}
