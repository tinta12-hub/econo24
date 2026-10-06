<?php
/**
 * 24/7 - Shift Handover Protocol API
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
        listHandovers($pdo);
        break;

    case 'details':
        getHandoverDetails($pdo);
        break;

    case 'prefill_data':
        getPrefillHandoverData($pdo);
        break;

    case 'create':
        createHandover($pdo, $user);
        break;

    case 'acknowledge':
        acknowledgeHandover($pdo, $user);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid handover action'], 400);
}

function listHandovers(PDO $pdo): void {
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
    $status = trim($_GET['status'] ?? '');

    $sql = "
        SELECT sh.*, 
               out_st.name as outgoing_shift_name, out_st.color as outgoing_shift_color,
               inc_st.name as incoming_shift_name, inc_st.color as incoming_shift_color,
               CONCAT(e_out.first_name, ' ', e_out.last_name) as outgoing_supervisor_name, e_out.employee_code as outgoing_code,
               CONCAT(e_inc.first_name, ' ', e_inc.last_name) as incoming_supervisor_name, e_inc.employee_code as incoming_code,
               b.name as branch_name
        FROM shift_handovers sh
        JOIN shift_templates out_st ON sh.outgoing_shift_template_id = out_st.id
        JOIN shift_templates inc_st ON sh.incoming_shift_template_id = inc_st.id
        JOIN employees e_out ON sh.outgoing_supervisor_id = e_out.id
        LEFT JOIN employees e_inc ON sh.incoming_supervisor_id = e_inc.id
        JOIN branches b ON sh.branch_id = b.id
        WHERE 1=1
    ";

    $params = [];
    if ($branchId !== null) {
        $sql .= " AND sh.branch_id = ?";
        $params[] = $branchId;
    }
    if (!empty($status) && $status !== 'all') {
        $sql .= " AND sh.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY sh.shift_date DESC, sh.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $handovers = $stmt->fetchAll();

    foreach ($handovers as &$h) {
        $h['checklist_array'] = !empty($h['checklist_json']) ? json_decode($h['checklist_json'], true) : [];
    }
    unset($h);

    jsonResponse(['success' => true, 'handovers' => $handovers, 'count' => count($handovers)]);
}

function getHandoverDetails(PDO $pdo): void {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT sh.*, 
               out_st.name as outgoing_shift_name, out_st.color as outgoing_shift_color, out_st.start_time as out_start, out_st.end_time as out_end,
               inc_st.name as incoming_shift_name, inc_st.color as incoming_shift_color, inc_st.start_time as inc_start, inc_st.end_time as inc_end,
               CONCAT(e_out.first_name, ' ', e_out.last_name) as outgoing_supervisor_name, e_out.employee_code as outgoing_code, e_out.role_title as outgoing_role,
               CONCAT(e_inc.first_name, ' ', e_inc.last_name) as incoming_supervisor_name, e_inc.employee_code as incoming_code, e_inc.role_title as incoming_role,
               b.name as branch_name
        FROM shift_handovers sh
        JOIN shift_templates out_st ON sh.outgoing_shift_template_id = out_st.id
        JOIN shift_templates inc_st ON sh.incoming_shift_template_id = inc_st.id
        JOIN employees e_out ON sh.outgoing_supervisor_id = e_out.id
        LEFT JOIN employees e_inc ON sh.incoming_supervisor_id = e_inc.id
        JOIN branches b ON sh.branch_id = b.id
        WHERE sh.id = ?
    ");
    $stmt->execute([$id]);
    $handover = $stmt->fetch();
    if (!$handover) {
        jsonResponse(['success' => false, 'message' => 'Handover report not found'], 404);
    }

    $handover['checklist_array'] = !empty($handover['checklist_json']) ? json_decode($handover['checklist_json'], true) : [];

    // Pending tasks for this branch / handover
    $tasksStmt = $pdo->prepare("
        SELECT id, title, priority, status, due_datetime, is_handover_task, handover_notes
        FROM tasks
        WHERE branch_id = ? AND (is_handover_task = 1 OR status IN ('pending', 'in_progress'))
        ORDER BY FIELD(priority, 'urgent', 'high', 'medium', 'low')
    ");
    $tasksStmt->execute([$handover['branch_id']]);
    $handover['linked_tasks'] = $tasksStmt->fetchAll();

    // Critical or pending customer tickets for handover
    $ticketStmt = $pdo->prepare("
        SELECT id, ticket_number, customer_name, channel, severity, status, subject, is_handover_flagged
        FROM customer_service_logs
        WHERE branch_id = ? AND (is_handover_flagged = 1 OR status IN ('open', 'in_progress', 'pending_handover'))
        ORDER BY FIELD(severity, 'critical_247', 'high', 'medium', 'low')
    ");
    $ticketStmt->execute([$handover['branch_id']]);
    $handover['linked_tickets'] = $ticketStmt->fetchAll();

    jsonResponse(['success' => true, 'handover' => $handover]);
}

function getPrefillHandoverData(PDO $pdo): void {
    $branchId = (int)($_GET['branch_id'] ?? 1);
    $shiftTemplateId = (int)($_GET['shift_template_id'] ?? 1);

    // Get open handover tasks
    $taskStmt = $pdo->prepare("
        SELECT id, title, priority, status, is_handover_task, handover_notes
        FROM tasks
        WHERE branch_id = ? AND (is_handover_task = 1 OR status IN ('pending', 'in_progress'))
        LIMIT 10
    ");
    $taskStmt->execute([$branchId]);
    $tasks = $taskStmt->fetchAll();

    // Get open customer tickets
    $ticketStmt = $pdo->prepare("
        SELECT id, ticket_number, customer_name, severity, status, subject
        FROM customer_service_logs
        WHERE branch_id = ? AND status IN ('open', 'in_progress', 'pending_handover')
        LIMIT 10
    ");
    $ticketStmt->execute([$branchId]);
    $tickets = $ticketStmt->fetchAll();

    // Default 24/7 Handover Checklist items
    $defaultChecklist = [
        ['category' => 'Facilities', 'item' => 'Exterior perimeter doors and emergency exits secure', 'status' => 'pass'],
        ['category' => 'Systems', 'item' => 'Operations consoles, servers and NOC telemetry running nominal', 'status' => 'pass'],
        ['category' => 'Cash/Safe', 'item' => 'Cash drawer / register counted and reconciled', 'status' => 'pass'],
        ['category' => 'Safety', 'item' => 'Fire alarm panels, suppression valves and first aid kits checked', 'status' => 'pass'],
        ['category' => 'Keys/Access', 'item' => 'Master keybox and restricted access badges accounted for', 'status' => 'pass']
    ];

    jsonResponse([
        'success' => true,
        'open_tasks' => $tasks,
        'open_tickets' => $tickets,
        'checklist' => $defaultChecklist
    ]);
}

function createHandover(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Only supervisors and managers can submit shift handovers'], 403);
    }

    $data = getRequestData();
    $branchId = (int)($data['branch_id'] ?? 1);
    $shiftDate = !empty($data['shift_date']) ? $data['shift_date'] : date('Y-m-d');
    $outgoingTemplateId = (int)($data['outgoing_shift_template_id'] ?? 0);
    $incomingTemplateId = (int)($data['incoming_shift_template_id'] ?? 0);
    $outgoingSupervisorId = !empty($data['outgoing_supervisor_id']) ? (int)$data['outgoing_supervisor_id'] : ($user['employee_id'] ?: 1);
    $incomingSupervisorId = !empty($data['incoming_supervisor_id']) ? (int)$data['incoming_supervisor_id'] : null;
    $handoverNotes = trim($data['handover_notes'] ?? '');
    $facilityStatus = in_array($data['facility_status'] ?? '', ['normal', 'needs_attention', 'critical_issue'], true) ? $data['facility_status'] : 'normal';
    $securityStatus = in_array($data['security_status'] ?? '', ['secure', 'incident_logged', 'patrol_needed'], true) ? $data['security_status'] : 'secure';
    $cashStatus = in_array($data['cash_status'] ?? '', ['reconciled', 'variance_reported', 'not_applicable'], true) ? $data['cash_status'] : 'reconciled';
    $equipmentStatus = in_array($data['equipment_status'] ?? '', ['all_operational', 'maintenance_required', 'faulty_unit'], true) ? $data['equipment_status'] : 'all_operational';
    $checklist = !empty($data['checklist']) ? (is_string($data['checklist']) ? $data['checklist'] : json_encode($data['checklist'])) : '[]';

    if (!$outgoingTemplateId || !$incomingTemplateId) {
        jsonResponse(['success' => false, 'message' => 'Both Outgoing and Incoming shift templates must be selected'], 422);
    }

    $insStmt = $pdo->prepare("
        INSERT INTO shift_handovers (
            branch_id, shift_date, outgoing_shift_template_id, incoming_shift_template_id,
            outgoing_supervisor_id, incoming_supervisor_id, status, handover_notes,
            facility_status, security_status, cash_status, equipment_status,
            checklist_json, outgoing_signed_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'pending_signoff', ?, ?, ?, ?, ?, ?, NOW())
    ");
    $insStmt->execute([
        $branchId, $shiftDate, $outgoingTemplateId, $incomingTemplateId,
        $outgoingSupervisorId, $incomingSupervisorId, $handoverNotes,
        $facilityStatus, $securityStatus, $cashStatus, $equipmentStatus,
        $checklist
    ]);
    $handoverId = (int)$pdo->lastInsertId();

    logAudit($pdo, (int)$user['id'], 'HANDOVER_CREATE', 'shift_handovers', $handoverId, [
        'outgoing_template' => $outgoingTemplateId,
        'incoming_template' => $incomingTemplateId,
        'branch_id' => $branchId
    ], $branchId);

    // Notify incoming supervisor
    createNotification(
        $pdo,
        'Shift Handover Awaiting Acceptance',
        "Outgoing supervisor {$user['username']} has signed and submitted shift handover #$handoverId. Please review and acknowledge.",
        'warning',
        $branchId,
        'handover',
        $handoverId
    );

    jsonResponse([
        'success' => true,
        'message' => 'Shift handover log submitted and signed as Outgoing Supervisor! Now pending Incoming Supervisor sign-off.',
        'handover_id' => $handoverId
    ]);
}

function acknowledgeHandover(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to acknowledge handover'], 403);
    }

    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);
    $incomingSupervisorId = !empty($data['incoming_supervisor_id']) ? (int)$data['incoming_supervisor_id'] : ($user['employee_id'] ?: 1);

    $stmt = $pdo->prepare("SELECT * FROM shift_handovers WHERE id = ?");
    $stmt->execute([$id]);
    $h = $stmt->fetch();
    if (!$h) {
        jsonResponse(['success' => false, 'message' => 'Handover not found'], 404);
    }

    $pdo->prepare("
        UPDATE shift_handovers SET
            status = 'completed',
            incoming_supervisor_id = COALESCE(?, incoming_supervisor_id),
            incoming_signed_at = NOW()
        WHERE id = ?
    ")->execute([$incomingSupervisorId, $id]);

    logAudit($pdo, (int)$user['id'], 'HANDOVER_ACKNOWLEDGE', 'shift_handovers', $id, [
        'incoming_supervisor' => $incomingSupervisorId,
        'status' => 'completed'
    ], (int)$h['branch_id']);

    createNotification(
        $pdo,
        'Shift Handover Completed & Acknowledged',
        "Incoming supervisor has reviewed and signed handover #$id. Continuous operation ownership transitioned.",
        'info',
        (int)$h['branch_id'],
        'handover',
        $id
    );

    jsonResponse([
        'success' => true,
        'message' => 'Handover successfully acknowledged and signed! Continuous 24/7 operational transition complete.'
    ]);
}
