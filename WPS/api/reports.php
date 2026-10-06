<?php
/**
 * 24/7 - Operational Reports & Export API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();

$type = $_GET['type'] ?? 'attendance';
$format = $_GET['format'] ?? 'json';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;

// Branch details
$branchName = 'All Branches';
if ($branchId) {
    $bStmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
    $bStmt->execute([$branchId]);
    $branchName = $bStmt->fetchColumn() ?: 'Branch #' . $branchId;
}

switch ($type) {
    case 'attendance':
        generateAttendanceReport($pdo, $startDate, $endDate, $branchId, $branchName, $format);
        break;

    case 'shifts':
        generateShiftCoverageReport($pdo, $startDate, $endDate, $branchId, $branchName, $format);
        break;

    case 'tasks':
        generateTaskProductivityReport($pdo, $startDate, $endDate, $branchId, $branchName, $format);
        break;

    case 'customer_service':
        generateCustomerServiceReport($pdo, $startDate, $endDate, $branchId, $branchName, $format);
        break;

    case 'labor_cost':
        generateLaborCostReport($pdo, $startDate, $endDate, $branchId, $branchName, $format);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid report type'], 400);
}

function generateAttendanceReport(PDO $pdo, string $startDate, string $endDate, ?int $branchId, string $branchName, string $format): void {
    $sql = "
        SELECT a.id, a.clock_in, a.clock_out, a.status, a.regular_hours, a.overtime_hours, a.night_hours, a.late_minutes, a.early_departure_minutes,
               e.employee_code, CONCAT(e.first_name, ' ', e.last_name) as employee_name, e.role_title,
               b.name as branch_name, st.name as shift_name
        FROM attendance a
        JOIN employees e ON a.employee_id = e.id
        JOIN branches b ON a.branch_id = b.id
        LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
        LEFT JOIN shift_templates st ON sa.shift_template_id = st.id
        WHERE DATE(a.clock_in) BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    if ($branchId !== null) {
        $sql .= " AND a.branch_id = ?";
        $params[] = $branchId;
    }
    $sql .= " ORDER BY a.clock_in DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($format === 'csv') {
        exportCsv("24_7_Attendance_Report_{$startDate}_to_{$endDate}.csv", [
            'ID', 'Employee Code', 'Employee Name', 'Role', 'Branch', 'Shift', 'Clock In', 'Clock Out', 'Status', 'Regular (hrs)', 'Night (hrs)', 'Overtime (hrs)', 'Late (mins)'
        ], array_map(function($r) {
            return [
                $r['id'], $r['employee_code'], $r['employee_name'], $r['role_title'], $r['branch_name'],
                $r['shift_name'] ?? 'Ad-hoc', $r['clock_in'], $r['clock_out'] ?? 'ON DUTY', strtoupper($r['status']),
                $r['regular_hours'], $r['night_hours'], $r['overtime_hours'], $r['late_minutes']
            ];
        }, $rows));
    }

    jsonResponse([
        'success' => true,
        'report_title' => '24-Hour Workforce Attendance & Punctuality Report',
        'date_range' => "$startDate to $endDate",
        'branch' => $branchName,
        'records' => $rows,
        'summary' => [
            'total_shifts_logged' => count($rows),
            'total_hours_worked' => round(array_sum(array_column($rows, 'regular_hours')) + array_sum(array_column($rows, 'overtime_hours')), 2),
            'total_night_hours' => round(array_sum(array_column($rows, 'night_hours')), 2),
            'late_arrivals_count' => count(array_filter($rows, fn($r) => $r['status'] === 'late'))
        ]
    ]);
}

function generateTaskProductivityReport(PDO $pdo, string $startDate, string $endDate, ?int $branchId, string $branchName, string $format): void {
    $sql = "
        SELECT t.id, t.title, t.priority, t.status, t.shift_date, t.due_datetime, t.completed_at, t.is_handover_task,
               CONCAT(e.first_name, ' ', e.last_name) as assigned_to,
               CONCAT(comp.first_name, ' ', comp.last_name) as completed_by,
               b.name as branch_name, d.name as department_name, st.name as shift_name
        FROM tasks t
        JOIN branches b ON t.branch_id = b.id
        LEFT JOIN departments d ON t.department_id = d.id
        LEFT JOIN employees e ON t.assigned_to_employee_id = e.id
        LEFT JOIN employees comp ON t.completed_by = comp.id
        LEFT JOIN shift_templates st ON t.shift_template_id = st.id
        WHERE t.shift_date BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    if ($branchId !== null) {
        $sql .= " AND t.branch_id = ?";
        $params[] = $branchId;
    }
    $sql .= " ORDER BY t.shift_date DESC, FIELD(t.priority, 'urgent', 'high', 'medium', 'low')";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($format === 'csv') {
        exportCsv("24_7_Task_Productivity_Report_{$startDate}_to_{$endDate}.csv", [
            'ID', 'Task Title', 'Priority', 'Status', 'Shift Date', 'Due Date', 'Completed At', 'Assigned To', 'Completed By', 'Branch', 'Department', 'Shift', 'Handover Task'
        ], array_map(function($r) {
            return [
                $r['id'], $r['title'], strtoupper($r['priority']), strtoupper($r['status']), $r['shift_date'],
                $r['due_datetime'], $r['completed_at'] ?? 'Pending', $r['assigned_to'] ?? 'Unassigned',
                $r['completed_by'] ?? 'N/A', $r['branch_name'], $r['department_name'] ?? 'N/A',
                $r['shift_name'] ?? 'General', $r['is_handover_task'] ? 'YES' : 'NO'
            ];
        }, $rows));
    }

    $completedCount = count(array_filter($rows, fn($r) => $r['status'] === 'completed'));
    $totalCount = count($rows);

    jsonResponse([
        'success' => true,
        'report_title' => '24-Hour Task Management & Productivity Report',
        'date_range' => "$startDate to $endDate",
        'branch' => $branchName,
        'records' => $rows,
        'summary' => [
            'total_tasks' => $totalCount,
            'completed_tasks' => $completedCount,
            'completion_rate' => $totalCount > 0 ? round(($completedCount / $totalCount) * 100, 1) . '%' : '100%',
            'handover_tasks_count' => count(array_filter($rows, fn($r) => (int)$r['is_handover_task'] === 1))
        ]
    ]);
}

function generateCustomerServiceReport(PDO $pdo, string $startDate, string $endDate, ?int $branchId, string $branchName, string $format): void {
    $sql = "
        SELECT cs.id, cs.ticket_number, cs.customer_name, cs.channel, cs.category, cs.severity, cs.status, cs.subject,
               cs.response_time_minutes, cs.resolution_time_minutes, cs.satisfaction_rating, cs.is_handover_flagged, cs.created_at, cs.resolved_at,
               CONCAT(e.first_name, ' ', e.last_name) as logged_by,
               b.name as branch_name, st.name as shift_name
        FROM customer_service_logs cs
        JOIN branches b ON cs.branch_id = b.id
        JOIN employees e ON cs.logged_by_employee_id = e.id
        LEFT JOIN shift_templates st ON cs.shift_template_id = st.id
        WHERE DATE(cs.created_at) BETWEEN ? AND ?
    ";
    $params = [$startDate, $endDate];
    if ($branchId !== null) {
        $sql .= " AND cs.branch_id = ?";
        $params[] = $branchId;
    }
    $sql .= " ORDER BY cs.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($format === 'csv') {
        exportCsv("24_7_Customer_Service_Report_{$startDate}_to_{$endDate}.csv", [
            'Ticket #', 'Customer Name', 'Channel', 'Category', 'Severity', 'Status', 'Subject', 'Response (min)', 'Resolution (min)', 'CSAT', 'Logged By', 'Branch', 'Shift', 'Handover Flagged'
        ], array_map(function($r) {
            return [
                $r['ticket_number'], $r['customer_name'], strtoupper($r['channel']), strtoupper($r['category']),
                strtoupper($r['severity']), strtoupper($r['status']), $r['subject'], $r['response_time_minutes'],
                $r['resolution_time_minutes'], $r['satisfaction_rating'] ?? 'N/A', $r['logged_by'],
                $r['branch_name'], $r['shift_name'] ?? 'General', $r['is_handover_flagged'] ? 'YES' : 'NO'
            ];
        }, $rows));
    }

    $ratings = array_filter(array_column($rows, 'satisfaction_rating'), fn($v) => $v !== null);
    $avgRating = count($ratings) > 0 ? round(array_sum($ratings) / count($ratings), 1) : 5.0;

    jsonResponse([
        'success' => true,
        'report_title' => '24-Hour Customer Service & Incident Report',
        'date_range' => "$startDate to $endDate",
        'branch' => $branchName,
        'records' => $rows,
        'summary' => [
            'total_tickets' => count($rows),
            'resolved_tickets' => count(array_filter($rows, fn($r) => $r['status'] === 'resolved')),
            'critical_incidents' => count(array_filter($rows, fn($r) => $r['severity'] === 'critical_247')),
            'average_csat' => $avgRating . ' / 5.0'
        ]
    ]);
}

function generateShiftCoverageReport(PDO $pdo, string $startDate, string $endDate, ?int $branchId, string $branchName, string $format): void {
    $sql = "
        SELECT sa.id, sa.shift_date, sa.start_datetime, sa.end_datetime, sa.status,
               CONCAT(e.first_name, ' ', e.last_name) as employee_name, e.employee_code, e.role_title,
               st.name as shift_name, st.start_time, st.end_time, st.is_overnight, st.duration_hours,
               b.name as branch_name
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
    $sql .= " ORDER BY sa.shift_date ASC, st.start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($format === 'csv') {
        exportCsv("24_7_Shift_Coverage_Report_{$startDate}_to_{$endDate}.csv", [
            'ID', 'Date', 'Shift Name', 'Overnight', 'Hours', 'Employee', 'Role', 'Status', 'Start Datetime', 'End Datetime', 'Branch'
        ], array_map(function($r) {
            return [
                $r['id'], $r['shift_date'], $r['shift_name'], $r['is_overnight'] ? 'YES' : 'NO', $r['duration_hours'],
                $r['employee_name'], $r['role_title'], strtoupper($r['status']), $r['start_datetime'], $r['end_datetime'], $r['branch_name']
            ];
        }, $rows));
    }

    jsonResponse([
        'success' => true,
        'report_title' => '24-Hour Shift Scheduling & Coverage Report',
        'date_range' => "$startDate to $endDate",
        'branch' => $branchName,
        'records' => $rows,
        'summary' => [
            'total_shifts_scheduled' => count($rows),
            'completed_shifts' => count(array_filter($rows, fn($r) => $r['status'] === 'completed')),
            'overnight_shifts' => count(array_filter($rows, fn($r) => (int)$r['is_overnight'] === 1))
        ]
    ]);
}

function generateLaborCostReport(PDO $pdo, string $startDate, string $endDate, ?int $branchId, string $branchName, string $format): void {
    $multiplierStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'night_differential_multiplier'");
    $nightMultiplier = (float)($multiplierStmt->fetchColumn() ?: 1.25);

    $sql = "
        SELECT e.employee_code, CONCAT(e.first_name, ' ', e.last_name) as employee_name, e.role_title, e.hourly_rate,
               b.name as branch_name,
               SUM(a.regular_hours) as regular_hours,
               SUM(a.night_hours) as night_hours,
               SUM(a.overtime_hours) as overtime_hours,
               ROUND(SUM(a.regular_hours * e.hourly_rate), 2) as base_cost,
               ROUND(SUM(a.night_hours * e.hourly_rate * (? - 1.0)), 2) as night_premium,
               ROUND(SUM(a.overtime_hours * e.hourly_rate * 1.5), 2) as overtime_cost,
               ROUND(SUM(
                   (a.regular_hours * e.hourly_rate) +
                   (a.night_hours * e.hourly_rate * (? - 1.0)) +
                   (a.overtime_hours * e.hourly_rate * 1.5)
               ), 2) as total_compensation
        FROM attendance a
        JOIN employees e ON a.employee_id = e.id
        JOIN branches b ON a.branch_id = b.id
        WHERE DATE(a.clock_in) BETWEEN ? AND ?
    ";
    $params = [$nightMultiplier, $nightMultiplier, $startDate, $endDate];
    if ($branchId !== null) {
        $sql .= " AND a.branch_id = ?";
        $params[] = $branchId;
    }
    $sql .= " GROUP BY e.id ORDER BY total_compensation DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($format === 'csv') {
        exportCsv("24_7_Labor_Cost_Report_{$startDate}_to_{$endDate}.csv", [
            'Code', 'Employee', 'Role', 'Hourly Rate', 'Branch', 'Regular (hrs)', 'Night (hrs)', 'OT (hrs)', 'Base Pay ($)', 'Night Premium ($)', 'Overtime Pay ($)', 'Total Pay ($)'
        ], array_map(function($r) {
            return [
                $r['employee_code'], $r['employee_name'], $r['role_title'], '$' . $r['hourly_rate'], $r['branch_name'],
                $r['regular_hours'], $r['night_hours'], $r['overtime_hours'], '$' . $r['base_cost'],
                '$' . $r['night_premium'], '$' . $r['overtime_cost'], '$' . $r['total_compensation']
            ];
        }, $rows));
    }

    $totalPayroll = array_sum(array_column($rows, 'total_compensation'));

    jsonResponse([
        'success' => true,
        'report_title' => '24-Hour Labor & Overtime Expenditure Report',
        'date_range' => "$startDate to $endDate",
        'branch' => $branchName,
        'records' => $rows,
        'summary' => [
            'total_payroll' => '$' . number_format($totalPayroll, 2),
            'total_night_premium' => '$' . number_format(array_sum(array_column($rows, 'night_premium')), 2),
            'total_overtime_cost' => '$' . number_format(array_sum(array_column($rows, 'overtime_cost')), 2),
            'night_wage_multiplier' => "{$nightMultiplier}x (" . (($nightMultiplier - 1) * 100) . "% premium)"
        ]
    ]);
}

function exportCsv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
