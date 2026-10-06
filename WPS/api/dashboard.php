<?php
/**
 * 24/7 - Dashboard & Operations Pulse API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();

$branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;

// Determine Current Operational Shift Phase
$currentTime = date('H:i:s');
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');

// Determine if we are in Night/Graveyard, Morning, or Afternoon
$shiftPhase = 'day';
$activeShiftName = 'Day Operations';
if ($currentHour >= 22 || $currentHour < 6) {
    $shiftPhase = 'night';
    $activeShiftName = 'Graveyard / Overnight Operations (22:00–06:00)';
} elseif ($currentHour >= 14 && $currentHour < 22) {
    $shiftPhase = 'afternoon';
    $activeShiftName = 'Afternoon / Swing Operations (14:00–22:00)';
} else {
    $shiftPhase = 'morning';
    $activeShiftName = 'Morning Operations (06:00–14:00)';
}

// 1. Live "On Duty Now" Employees (clock_in IS NOT NULL AND clock_out IS NULL)
$onDutySql = "
    SELECT a.id as attendance_id, a.clock_in, a.status as attendance_status,
           e.id as employee_id, e.employee_code, e.first_name, e.last_name, e.role_title, e.avatar_color,
           b.name as branch_name, d.name as department_name,
           st.name as shift_name, st.color as shift_color,
           (SELECT break_type FROM breaks WHERE attendance_id = a.id AND end_time IS NULL LIMIT 1) as current_break
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    JOIN branches b ON a.branch_id = b.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
    LEFT JOIN shift_templates st ON sa.shift_template_id = st.id
    WHERE a.clock_out IS NULL
";
$params = [];
if ($branchId !== null) {
    $onDutySql .= " AND a.branch_id = ?";
    $params[] = $branchId;
}
$onDutySql .= " ORDER BY a.clock_in DESC";
$onDutyStmt = $pdo->prepare($onDutySql);
$onDutyStmt->execute($params);
$onDutyList = $onDutyStmt->fetchAll();

// Calculate duration worked for each on-duty employee
foreach ($onDutyList as &$emp) {
    $startTs = strtotime($emp['clock_in']);
    $diffMinutes = max(0, (int)round((time() - $startTs) / 60));
    $hours = floor($diffMinutes / 60);
    $mins = $diffMinutes % 60;
    $emp['time_worked'] = sprintf('%dh %02dm', $hours, $mins);
    $emp['clock_in_formatted'] = date('H:i', $startTs);
}
unset($emp);

// 2. Summary KPIs
// Scheduled Shifts Today
$schedSql = "SELECT COUNT(*) FROM shift_assignments WHERE shift_date = ?";
$schedParams = [$currentDate];
if ($branchId !== null) {
    $schedSql .= " AND branch_id = ?";
    $schedParams[] = $branchId;
}
$schedStmt = $pdo->prepare($schedSql);
$schedStmt->execute($schedParams);
$totalScheduledToday = (int)$schedStmt->fetchColumn();

// Active / Completed Shifts Today
$activeShiftsSql = "SELECT COUNT(*) FROM shift_assignments WHERE shift_date = ? AND status IN ('in_progress', 'completed')";
$activeShiftsStmt = $pdo->prepare($branchId ? "$activeShiftsSql AND branch_id = ?" : $activeShiftsSql);
$activeShiftsStmt->execute($branchId ? [$currentDate, $branchId] : [$currentDate]);
$activeShiftsToday = (int)$activeShiftsStmt->fetchColumn();

// Open Tasks
$taskSql = "SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'in_progress')";
$taskParams = [];
if ($branchId !== null) {
    $taskSql .= " AND branch_id = ?";
    $taskParams[] = $branchId;
}
$taskStmt = $pdo->prepare($taskSql);
$taskStmt->execute($taskParams);
$openTasksCount = (int)$taskStmt->fetchColumn();

// Overdue Tasks
$overdueSql = "SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'in_progress') AND due_datetime < NOW()";
if ($branchId !== null) {
    $overdueSql .= " AND branch_id = ?";
}
$overdueStmt = $pdo->prepare($overdueSql);
$overdueStmt->execute($branchId ? [$branchId] : []);
$overdueTasksCount = (int)$overdueStmt->fetchColumn();

// Open Customer Tickets & Critical Count
$ticketSql = "SELECT COUNT(*) as open_count, SUM(CASE WHEN severity = 'critical_247' THEN 1 ELSE 0 END) as critical_count FROM customer_service_logs WHERE status IN ('open', 'in_progress', 'pending_handover')";
if ($branchId !== null) {
    $ticketSql .= " AND branch_id = ?";
}
$ticketStmt = $pdo->prepare($ticketSql);
$ticketStmt->execute($branchId ? [$branchId] : []);
$ticketStats = $ticketStmt->fetch();
$openTicketsCount = (int)($ticketStats['open_count'] ?? 0);
$criticalTicketsCount = (int)($ticketStats['critical_count'] ?? 0);

// Pending Shift Handovers awaiting acceptance
$handoverSql = "SELECT COUNT(*) FROM shift_handovers WHERE status IN ('pending_signoff', 'draft')";
if ($branchId !== null) {
    $handoverSql .= " AND branch_id = ?";
}
$handoverStmt = $pdo->prepare($handoverSql);
$handoverStmt->execute($branchId ? [$branchId] : []);
$pendingHandoversCount = (int)$handoverStmt->fetchColumn();

// 3. Today's Shift Roster Coverage (Morning, Afternoon, Graveyard)
$rosterSql = "
    SELECT st.id, st.name, st.shift_code, st.start_time, st.end_time, st.is_overnight, st.color, st.min_staff_required,
           COUNT(sa.id) as scheduled_count,
           SUM(CASE WHEN sa.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
           SUM(CASE WHEN sa.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count
    FROM shift_templates st
    LEFT JOIN shift_assignments sa ON st.id = sa.shift_template_id AND sa.shift_date = ? " . ($branchId ? "AND sa.branch_id = $branchId" : "") . "
    WHERE st.is_active = 1
    GROUP BY st.id
    ORDER BY st.start_time ASC
";
$rosterStmt = $pdo->prepare($rosterSql);
$rosterStmt->execute([$currentDate]);
$shiftCoverage = $rosterStmt->fetchAll();

// 4. 24-Hour Activity Pulse Curve (Hours 00 to 23 for today & yesterday overnight)
$pulseHours = [];
for ($h = 0; $h < 24; $h++) {
    $pulseHours[$h] = [
        'hour' => sprintf('%02d:00', $h),
        'attendance_clockins' => 0,
        'tasks_completed' => 0,
        'customer_tickets' => 0
    ];
}

// Clock ins by hour today
$attHourSql = "SELECT HOUR(clock_in) as hr, COUNT(*) as cnt FROM attendance WHERE DATE(clock_in) = ? GROUP BY HOUR(clock_in)";
$attHourStmt = $pdo->prepare($attHourSql);
$attHourStmt->execute([$currentDate]);
while ($row = $attHourStmt->fetch()) {
    $hr = (int)$row['hr'];
    if (isset($pulseHours[$hr])) {
        $pulseHours[$hr]['attendance_clockins'] = (int)$row['cnt'];
    }
}

// Tasks completed by hour today
$taskHourSql = "SELECT HOUR(completed_at) as hr, COUNT(*) as cnt FROM tasks WHERE DATE(completed_at) = ? AND status = 'completed' GROUP BY HOUR(completed_at)";
$taskHourStmt = $pdo->prepare($taskHourSql);
$taskHourStmt->execute([$currentDate]);
while ($row = $taskHourStmt->fetch()) {
    $hr = (int)$row['hr'];
    if (isset($pulseHours[$hr])) {
        $pulseHours[$hr]['tasks_completed'] = (int)$row['cnt'];
    }
}

// Customer tickets by hour today
$csHourSql = "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM customer_service_logs WHERE DATE(created_at) = ? GROUP BY HOUR(created_at)";
$csHourStmt = $pdo->prepare($csHourSql);
$csHourStmt->execute([$currentDate]);
while ($row = $csHourStmt->fetch()) {
    $hr = (int)$row['hr'];
    if (isset($pulseHours[$hr])) {
        $pulseHours[$hr]['customer_tickets'] = (int)$row['cnt'];
    }
}

// 5. Recent System Operations Feed (Audit Logs)
$feedSql = "
    SELECT a.*, u.username, u.role, e.first_name, e.last_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN employees e ON u.employee_id = e.id
    ORDER BY a.created_at DESC
    LIMIT 10
";
$feed = $pdo->query($feedSql)->fetchAll();

// 6. Active Shift Handover Banner / Alert
$activeHandoverSql = "
    SELECT sh.*, 
           out_st.name as outgoing_shift_name, inc_st.name as incoming_shift_name,
           CONCAT(e_out.first_name, ' ', e_out.last_name) as outgoing_supervisor,
           CONCAT(e_inc.first_name, ' ', e_inc.last_name) as incoming_supervisor,
           b.name as branch_name
    FROM shift_handovers sh
    JOIN shift_templates out_st ON sh.outgoing_shift_template_id = out_st.id
    JOIN shift_templates inc_st ON sh.incoming_shift_template_id = inc_st.id
    JOIN employees e_out ON sh.outgoing_supervisor_id = e_out.id
    LEFT JOIN employees e_inc ON sh.incoming_supervisor_id = e_inc.id
    JOIN branches b ON sh.branch_id = b.id
    WHERE sh.status IN ('pending_signoff', 'draft')
    ORDER BY sh.id DESC
    LIMIT 1
";
$activeHandover = $pdo->query($activeHandoverSql)->fetch();

jsonResponse([
    'success' => true,
    'data' => [
        'current_time' => date('Y-m-d H:i:s'),
        'shift_phase' => $shiftPhase,
        'active_shift_name' => $activeShiftName,
        'metrics' => [
            'on_duty_count' => count($onDutyList),
            'total_scheduled_today' => $totalScheduledToday,
            'active_shifts_today' => $activeShiftsToday,
            'shift_coverage_pct' => $totalScheduledToday > 0 ? round(($activeShiftsToday / $totalScheduledToday) * 100) : 100,
            'open_tasks_count' => $openTasksCount,
            'overdue_tasks_count' => $overdueTasksCount,
            'open_tickets_count' => $openTicketsCount,
            'critical_tickets_count' => $criticalTicketsCount,
            'pending_handovers_count' => $pendingHandoversCount
        ],
        'on_duty_employees' => $onDutyList,
        'shift_coverage' => $shiftCoverage,
        'activity_pulse' => array_values($pulseHours),
        'recent_feed' => $feed,
        'active_handover' => $activeHandover
    ]
]);
