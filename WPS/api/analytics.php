<?php
/**
 * 24/7 - Business Analytics & 24-Hour Operational Metrics API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();

$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;

// Get settings
$settings = [];
$settingsRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
foreach ($settingsRows as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}
$nightMultiplier = (float)($settings['night_differential_multiplier'] ?? 1.25);
$currency = $settings['currency_symbol'] ?? 'ZMW';

// 1. Shift Comparison (Morning vs Afternoon vs Overnight)
$shiftStatsSql = "
    SELECT st.id, st.name, st.shift_code, st.start_time, st.end_time, st.is_overnight, st.color,
           COUNT(DISTINCT sa.id) as scheduled_count,
           COUNT(DISTINCT a.id) as clocked_in_count,
           SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
           COALESCE(SUM(a.regular_hours), 0) as total_regular_hours,
           COALESCE(SUM(a.overtime_hours), 0) as total_overtime_hours,
           COALESCE(SUM(a.night_hours), 0) as total_night_hours,
           (SELECT COUNT(*) FROM tasks t WHERE t.shift_template_id = st.id AND t.shift_date BETWEEN ? AND ? AND t.status = 'completed') as tasks_completed,
           (SELECT COUNT(*) FROM customer_service_logs c WHERE c.shift_template_id = st.id AND DATE(c.created_at) BETWEEN ? AND ?) as customer_tickets,
           (SELECT ROUND(AVG(c.satisfaction_rating), 1) FROM customer_service_logs c WHERE c.shift_template_id = st.id AND DATE(c.created_at) BETWEEN ? AND ? AND c.satisfaction_rating IS NOT NULL) as avg_csat
    FROM shift_templates st
    LEFT JOIN shift_assignments sa ON st.id = sa.shift_template_id AND sa.shift_date BETWEEN ? AND ? " . ($branchId ? "AND sa.branch_id = $branchId" : "") . "
    LEFT JOIN attendance a ON sa.id = a.shift_assignment_id
    WHERE st.is_active = 1
    GROUP BY st.id
    ORDER BY st.start_time ASC
";
$shiftStatsStmt = $pdo->prepare($shiftStatsSql);
$shiftStatsStmt->execute([
    $startDate, $endDate,
    $startDate, $endDate,
    $startDate, $endDate,
    $startDate, $endDate
]);
$shiftComparison = $shiftStatsStmt->fetchAll();

foreach ($shiftComparison as &$sc) {
    $sched = (int)$sc['scheduled_count'];
    $clocked = (int)$sc['clocked_in_count'];
    $late = (int)$sc['late_count'];
    $onTime = max(0, $clocked - $late);
    $sc['punctuality_pct'] = $clocked > 0 ? round(($onTime / $clocked) * 100, 1) : 100.0;
    $sc['coverage_pct'] = $sched > 0 ? round(($clocked / $sched) * 100, 1) : 100.0;
}
unset($sc);

// 2. Day vs Night Operational Breakdown
$dayNightSql = "
    SELECT 
        SUM(regular_hours) as day_regular_hours,
        SUM(night_hours) as total_night_hours,
        SUM(overtime_hours) as total_overtime_hours,
        SUM(regular_hours * e.hourly_rate) as base_labor_cost,
        SUM(night_hours * e.hourly_rate * ?) as night_differential_cost,
        SUM(overtime_hours * e.hourly_rate * 1.5) as overtime_cost
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    WHERE DATE(a.clock_in) BETWEEN ? AND ?
";
if ($branchId !== null) {
    $dayNightSql .= " AND a.branch_id = $branchId";
}
$dayNightStmt = $pdo->prepare($dayNightSql);
$dayNightStmt->execute([$nightMultiplier - 1.0, $startDate, $endDate]);
$laborStats = $dayNightStmt->fetch();

$baseLabor = round((float)($laborStats['base_labor_cost'] ?? 0), 2);
$nightDiff = round((float)($laborStats['night_differential_cost'] ?? 0), 2);
$otCost = round((float)($laborStats['overtime_cost'] ?? 0), 2);
$totalLabor = round($baseLabor + $nightDiff + $otCost, 2);

// 3. 24-Hour Heatmap / Activity Distribution (Aggregate 00 to 23 across date range)
$hourlyDistribution = [];
for ($h = 0; $h < 24; $h++) {
    $hourlyDistribution[$h] = [
        'hour' => sprintf('%02d:00', $h),
        'clockins' => 0,
        'tickets' => 0,
        'tasks' => 0
    ];
}

$hAttSql = "SELECT HOUR(clock_in) as hr, COUNT(*) as cnt FROM attendance WHERE DATE(clock_in) BETWEEN ? AND ? " . ($branchId ? "AND branch_id = $branchId" : "") . " GROUP BY HOUR(clock_in)";
$hAttStmt = $pdo->prepare($hAttSql);
$hAttStmt->execute([$startDate, $endDate]);
while ($r = $hAttStmt->fetch()) {
    $hr = (int)$r['hr'];
    if (isset($hourlyDistribution[$hr])) $hourlyDistribution[$hr]['clockins'] = (int)$r['cnt'];
}

$hTickSql = "SELECT HOUR(created_at) as hr, COUNT(*) as cnt FROM customer_service_logs WHERE DATE(created_at) BETWEEN ? AND ? " . ($branchId ? "AND branch_id = $branchId" : "") . " GROUP BY HOUR(created_at)";
$hTickStmt = $pdo->prepare($hTickSql);
$hTickStmt->execute([$startDate, $endDate]);
while ($r = $hTickStmt->fetch()) {
    $hr = (int)$r['hr'];
    if (isset($hourlyDistribution[$hr])) $hourlyDistribution[$hr]['tickets'] = (int)$r['cnt'];
}

$hTaskSql = "SELECT HOUR(completed_at) as hr, COUNT(*) as cnt FROM tasks WHERE status = 'completed' AND DATE(completed_at) BETWEEN ? AND ? " . ($branchId ? "AND branch_id = $branchId" : "") . " GROUP BY HOUR(completed_at)";
$hTaskStmt = $pdo->prepare($hTaskSql);
$hTaskStmt->execute([$startDate, $endDate]);
while ($r = $hTaskStmt->fetch()) {
    $hr = (int)$r['hr'];
    if (isset($hourlyDistribution[$hr])) $hourlyDistribution[$hr]['tasks'] = (int)$r['cnt'];
}

// 4. Department Productivity Breakdown
$deptSql = "
    SELECT d.id, d.name as department_name, d.code,
           COUNT(DISTINCT e.id) as staff_count,
           (SELECT COUNT(*) FROM tasks t WHERE t.department_id = d.id AND t.shift_date BETWEEN ? AND ?) as total_tasks,
           (SELECT COUNT(*) FROM tasks t WHERE t.department_id = d.id AND t.shift_date BETWEEN ? AND ? AND t.status = 'completed') as completed_tasks,
           (SELECT COUNT(*) FROM customer_service_logs c WHERE c.department_id = d.id AND DATE(c.created_at) BETWEEN ? AND ?) as customer_tickets
    FROM departments d
    LEFT JOIN employees e ON d.id = e.department_id AND e.status = 'active'
    GROUP BY d.id
";
$deptStmt = $pdo->prepare($deptSql);
$deptStmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
$departments = $deptStmt->fetchAll();

foreach ($departments as &$d) {
    $tot = (int)$d['total_tasks'];
    $cmp = (int)$d['completed_tasks'];
    $d['task_completion_pct'] = $tot > 0 ? round(($cmp / $tot) * 100, 1) : 100.0;
}
unset($d);

// 5. Customer Service Channels & Severity Breakdown
$channelSql = "SELECT channel, COUNT(*) as cnt FROM customer_service_logs WHERE DATE(created_at) BETWEEN ? AND ? " . ($branchId ? "AND branch_id = $branchId" : "") . " GROUP BY channel";
$channelStmt = $pdo->prepare($channelSql);
$channelStmt->execute([$startDate, $endDate]);
$channels = $channelStmt->fetchAll();

$severitySql = "SELECT severity, COUNT(*) as cnt FROM customer_service_logs WHERE DATE(created_at) BETWEEN ? AND ? " . ($branchId ? "AND branch_id = $branchId" : "") . " GROUP BY severity";
$severityStmt = $pdo->prepare($severitySql);
$severityStmt->execute([$startDate, $endDate]);
$severities = $severityStmt->fetchAll();

// 6. Overall 24/7 Operations Health Index (0-100%)
$totalAtt = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(clock_in) BETWEEN '$startDate' AND '$endDate'")->fetchColumn();
$lateAtt = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE status = 'late' AND DATE(clock_in) BETWEEN '$startDate' AND '$endDate'")->fetchColumn();
$punctualityRate = $totalAtt > 0 ? (($totalAtt - $lateAtt) / $totalAtt) : 1.0;

$allTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE shift_date BETWEEN '$startDate' AND '$endDate'")->fetchColumn();
$doneTasks = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'completed' AND shift_date BETWEEN '$startDate' AND '$endDate'")->fetchColumn();
$taskRate = $allTasks > 0 ? ($doneTasks / $allTasks) : 1.0;

$allTickets = (int)$pdo->query("SELECT COUNT(*) FROM customer_service_logs WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'")->fetchColumn();
$resolvedTickets = (int)$pdo->query("SELECT COUNT(*) FROM customer_service_logs WHERE status = 'resolved' AND DATE(created_at) BETWEEN '$startDate' AND '$endDate'")->fetchColumn();
$ticketRate = $allTickets > 0 ? ($resolvedTickets / $allTickets) : 1.0;

$healthScore = round(($punctualityRate * 0.35 + $taskRate * 0.35 + $ticketRate * 0.30) * 100);

jsonResponse([
    'success' => true,
    'date_range' => ['start' => $startDate, 'end' => $endDate],
    'health_score' => $healthScore,
    'shift_comparison' => $shiftComparison,
    'hourly_distribution' => array_values($hourlyDistribution),
    'labor_analytics' => [
        'regular_hours' => round((float)($laborStats['day_regular_hours'] ?? 0), 1),
        'night_hours' => round((float)($laborStats['total_night_hours'] ?? 0), 1),
        'overtime_hours' => round((float)($laborStats['total_overtime_hours'] ?? 0), 1),
        'base_cost' => $baseLabor,
        'night_differential_cost' => $nightDiff,
        'overtime_cost' => $otCost,
        'total_labor_cost' => $totalLabor,
        'currency' => $currency
    ],
    'departments' => $departments,
    'channels' => $channels,
    'severities' => $severities
]);
