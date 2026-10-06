<?php
/**
 * 24/7 - Attendance Tracking & Live Duty Management API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'GET' ? 'live_duty' : '');

switch ($action) {
    case 'live_duty':
        getLiveDutyBoard($pdo);
        break;

    case 'history':
        getAttendanceHistory($pdo);
        break;

    case 'clock_in':
        handleClockIn($pdo, $user);
        break;

    case 'clock_out':
        handleClockOut($pdo, $user);
        break;

    case 'break_toggle':
        handleBreakToggle($pdo, $user);
        break;

    case 'manual_adjustment':
        handleManualAdjustment($pdo, $user);
        break;

    case 'employee_status':
        getEmployeeDutyStatus($pdo);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid attendance action'], 400);
}

function getLiveDutyBoard(PDO $pdo): void {
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;

    $sql = "
        SELECT a.*, 
               e.first_name, e.last_name, e.employee_code, e.role_title, e.avatar_color, e.shift_preference,
               b.name as branch_name, b.code as branch_code,
               d.name as department_name,
               st.name as shift_name, st.start_time as shift_start, st.end_time as shift_end, st.is_overnight, st.color as shift_color,
               sa.start_datetime as scheduled_start, sa.end_datetime as scheduled_end,
               b_curr.id as active_break_id, b_curr.break_type as active_break_type, b_curr.start_time as break_start_time
        FROM attendance a
        JOIN employees e ON a.employee_id = e.id
        JOIN branches b ON a.branch_id = b.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
        LEFT JOIN shift_templates st ON sa.shift_template_id = st.id
        LEFT JOIN breaks b_curr ON b_curr.attendance_id = a.id AND b_curr.end_time IS NULL
        WHERE a.clock_out IS NULL
    ";

    $params = [];
    if ($branchId !== null) {
        $sql .= " AND a.branch_id = ?";
        $params[] = $branchId;
    }
    $sql .= " ORDER BY a.clock_in DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    foreach ($records as &$rec) {
        $inTs = strtotime($rec['clock_in']);
        $diff = max(0, time() - $inTs);
        $rec['time_worked'] = sprintf('%dh %02dm', floor($diff / 3600), floor(($diff % 3600) / 60));
        $rec['clock_in_formatted'] = date('H:i:s', $inTs);

        if (!empty($rec['break_start_time'])) {
            $brkTs = strtotime($rec['break_start_time']);
            $brkDiff = max(0, time() - $brkTs);
            $rec['break_time_elapsed'] = sprintf('%dm', floor($brkDiff / 60));
        }
    }
    unset($rec);

    jsonResponse(['success' => true, 'on_duty' => $records, 'count' => count($records)]);
}

function getAttendanceHistory(PDO $pdo): void {
    $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
    $employeeId = isset($_GET['employee_id']) && $_GET['employee_id'] !== 'all' ? (int)$_GET['employee_id'] : null;
    $status = trim($_GET['status'] ?? '');

    $sql = "
        SELECT a.*, 
               e.first_name, e.last_name, e.employee_code, e.role_title, e.avatar_color,
               b.name as branch_name,
               st.name as shift_name, st.is_overnight, st.color as shift_color
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
    if ($employeeId !== null) {
        $sql .= " AND a.employee_id = ?";
        $params[] = $employeeId;
    }
    if (!empty($status) && $status !== 'all') {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY a.clock_in DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    jsonResponse(['success' => true, 'history' => $records, 'count' => count($records)]);
}

function handleClockIn(PDO $pdo, array $user): void {
    $data = getRequestData();
    $employeeId = (int)($data['employee_id'] ?? 0);
    $empCode = trim($data['employee_code'] ?? '');

    // Allow lookup by employee_code (kiosk terminal friendly)
    if (!$employeeId && !empty($empCode)) {
        $chkEmp = $pdo->prepare("SELECT id FROM employees WHERE employee_code = ? AND status = 'active'");
        $chkEmp->execute([$empCode]);
        $employeeId = (int)$chkEmp->fetchColumn();
    }

    if (!$employeeId) {
        jsonResponse(['success' => false, 'message' => 'Valid employee required for clock-in'], 422);
    }

    // Get Employee Details
    $empStmt = $pdo->prepare("SELECT e.*, b.name as branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.id = ?");
    $empStmt->execute([$employeeId]);
    $emp = $empStmt->fetch();
    if (!$emp) {
        jsonResponse(['success' => false, 'message' => 'Employee not found'], 404);
    }

    // Check if ALREADY clocked in
    $openStmt = $pdo->prepare("SELECT id, clock_in FROM attendance WHERE employee_id = ? AND clock_out IS NULL");
    $openStmt->execute([$employeeId]);
    $existing = $openStmt->fetch();
    if ($existing) {
        jsonResponse([
            'success' => false,
            'message' => "{$emp['first_name']} {$emp['last_name']} is ALREADY clocked in since {$existing['clock_in']}. Please clock out before clocking in again.",
            'attendance_id' => $existing['id']
        ], 409);
    }

    // Fetch Grace Period from Settings (default 15)
    $graceStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'grace_period_minutes'");
    $graceMinutes = (int)($graceStmt->fetchColumn() ?: 15);

    $now = date('Y-m-d H:i:s');
    $nowTs = time();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // SMART SHIFT MATCHING: Find scheduled shift assignment matching today or overnight
    // Matches shift where current time is between (start - 2 hours) and (end + 2 hours)
    $matchSql = "
        SELECT sa.*, st.name as shift_name, st.start_time, st.end_time, st.is_overnight
        FROM shift_assignments sa
        JOIN shift_templates st ON sa.shift_template_id = st.id
        WHERE sa.employee_id = ?
        AND sa.status IN ('scheduled', 'confirmed', 'in_progress')
        AND ? BETWEEN DATE_SUB(sa.start_datetime, INTERVAL 2 HOUR) AND DATE_ADD(sa.end_datetime, INTERVAL 2 HOUR)
        ORDER BY ABS(TIMESTAMPDIFF(MINUTE, sa.start_datetime, ?)) ASC
        LIMIT 1
    ";
    $matchStmt = $pdo->prepare($matchSql);
    $matchStmt->execute([$employeeId, $now, $now]);
    $matchedShift = $matchStmt->fetch();

    $shiftAssignId = null;
    $scheduledIn = null;
    $scheduledOut = null;
    $status = 'present';
    $lateMinutes = 0;
    $notes = trim($data['notes'] ?? '');

    if ($matchedShift) {
        $shiftAssignId = (int)$matchedShift['id'];
        $scheduledIn = $matchedShift['start_datetime'];
        $scheduledOut = $matchedShift['end_datetime'];

        $schedInTs = strtotime($scheduledIn);
        $diffSeconds = $nowTs - $schedInTs;

        // Check if Late
        if ($diffSeconds > ($graceMinutes * 60)) {
            $status = 'late';
            $lateMinutes = (int)round($diffSeconds / 60);
        }

        // Mark shift assignment as in_progress
        $pdo->prepare("UPDATE shift_assignments SET status = 'in_progress' WHERE id = ?")->execute([$shiftAssignId]);
    } else {
        $notes .= ($notes ? ' | ' : '') . 'Unscheduled / Ad-hoc clock-in';
    }

    // Insert Attendance
    $insStmt = $pdo->prepare("
        INSERT INTO attendance (branch_id, employee_id, shift_assignment_id, clock_in, scheduled_in, scheduled_out, status, late_minutes, clock_in_ip, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insStmt->execute([
        $emp['branch_id'], $employeeId, $shiftAssignId, $now, $scheduledIn, $scheduledOut, $status, $lateMinutes, $ip, $notes
    ]);
    $attendanceId = (int)$pdo->lastInsertId();

    logAudit($pdo, (int)$user['id'], 'CLOCK_IN', 'attendance', $attendanceId, [
        'employee' => "{$emp['first_name']} {$emp['last_name']}",
        'status' => $status,
        'shift' => $matchedShift['shift_name'] ?? 'Ad-hoc Shift',
        'late_mins' => $lateMinutes
    ], (int)$emp['branch_id']);

    if ($status === 'late') {
        createNotification(
            $pdo,
            'Late Clock-In Recorded',
            "{$emp['first_name']} {$emp['last_name']} clocked in $lateMinutes minutes late for {$matchedShift['shift_name']}.",
            'warning',
            (int)$emp['branch_id'],
            'attendance',
            $attendanceId
        );
    }

    jsonResponse([
        'success' => true,
        'message' => "Clock-in recorded successfully for {$emp['first_name']} {$emp['last_name']}! Status: " . strtoupper($status) . ($lateMinutes ? " ($lateMinutes mins late)" : ""),
        'attendance_id' => $attendanceId,
        'status' => $status,
        'shift_name' => $matchedShift['shift_name'] ?? 'Ad-hoc'
    ]);
}

function handleClockOut(PDO $pdo, array $user): void {
    $data = getRequestData();
    $attendanceId = (int)($data['attendance_id'] ?? 0);
    $employeeId = (int)($data['employee_id'] ?? 0);

    // If attendance_id not provided, find active record for employee
    if (!$attendanceId && $employeeId) {
        $openStmt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND clock_out IS NULL LIMIT 1");
        $openStmt->execute([$employeeId]);
        $attendanceId = (int)$openStmt->fetchColumn();
    }

    if (!$attendanceId) {
        jsonResponse(['success' => false, 'message' => 'Active attendance record not found for clock out'], 404);
    }

    $attStmt = $pdo->prepare("
        SELECT a.*, e.first_name, e.last_name, e.hourly_rate,
               sa.start_datetime as sched_start, sa.end_datetime as sched_end
        FROM attendance a
        JOIN employees e ON a.employee_id = e.id
        LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
        WHERE a.id = ? AND a.clock_out IS NULL
    ");
    $attStmt->execute([$attendanceId]);
    $att = $attStmt->fetch();
    if (!$att) {
        jsonResponse(['success' => false, 'message' => 'Attendance record is already clocked out or not found'], 404);
    }

    // End any open breaks automatically
    $pdo->prepare("UPDATE breaks SET end_time = NOW(), duration_minutes = TIMESTAMPDIFF(MINUTE, start_time, NOW()) WHERE attendance_id = ? AND end_time IS NULL")
        ->execute([$attendanceId]);

    $clockInTs = strtotime($att['clock_in']);
    $clockOutTs = time();
    $now = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Total hours worked
    $totalHours = round(($clockOutTs - $clockInTs) / 3600, 2);

    // Calculate regular hours and overtime (standard shift regular = up to 8.0, beyond is overtime)
    $regularHours = min(8.0, $totalHours);
    $overtimeHours = max(0.0, round($totalHours - $regularHours, 2));

    // Calculate Night Hours (Hours between 22:00 and 06:00)
    $nightHours = 0.0;
    $cursor = $clockInTs;
    while ($cursor < $clockOutTs) {
        $hr = (int)date('G', $cursor);
        if ($hr >= 22 || $hr < 6) {
            $nightHours += (60 / 3600); // 1 minute chunk
        }
        $cursor += 60;
    }
    $nightHours = round($nightHours, 2);

    // Early departure check if scheduled_out exists
    $earlyDepartureMins = 0;
    $status = $att['status'];
    if (!empty($att['scheduled_out'])) {
        $schedOutTs = strtotime($att['scheduled_out']);
        if ($schedOutTs - $clockOutTs > (15 * 60)) {
            $earlyDepartureMins = (int)round(($schedOutTs - $clockOutTs) / 60);
            if ($status !== 'late') {
                $status = 'left_early';
            }
        }
    }
    if ($overtimeHours > 0) {
        $status = 'overtime';
    }

    $updStmt = $pdo->prepare("
        UPDATE attendance SET
            clock_out = ?, status = ?, regular_hours = ?, overtime_hours = ?, night_hours = ?,
            early_departure_minutes = ?, clock_out_ip = ?
        WHERE id = ?
    ");
    $updStmt->execute([
        $now, $status, $regularHours, $overtimeHours, $nightHours, $earlyDepartureMins, $ip, $attendanceId
    ]);

    // Mark shift assignment completed
    if (!empty($att['shift_assignment_id'])) {
        $pdo->prepare("UPDATE shift_assignments SET status = 'completed' WHERE id = ?")
            ->execute([$att['shift_assignment_id']]);
    }

    logAudit($pdo, (int)$user['id'], 'CLOCK_OUT', 'attendance', $attendanceId, [
        'employee' => "{$att['first_name']} {$att['last_name']}",
        'total_hours' => $totalHours,
        'regular_hours' => $regularHours,
        'overtime_hours' => $overtimeHours,
        'night_hours' => $nightHours
    ], (int)$att['branch_id']);

    jsonResponse([
        'success' => true,
        'message' => "Clock-out completed for {$att['first_name']} {$att['last_name']}. Total worked: {$totalHours}h (Regular: {$regularHours}h, Night Hours: {$nightHours}h, OT: {$overtimeHours}h).",
        'total_hours' => $totalHours,
        'night_hours' => $nightHours,
        'overtime_hours' => $overtimeHours
    ]);
}

function handleBreakToggle(PDO $pdo, array $user): void {
    $data = getRequestData();
    $attendanceId = (int)($data['attendance_id'] ?? 0);
    $breakType = in_array($data['break_type'] ?? '', ['meal', 'rest', 'night_refreshment'], true) ? $data['break_type'] : 'meal';

    $attStmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ? AND clock_out IS NULL");
    $attStmt->execute([$attendanceId]);
    $att = $attStmt->fetch();
    if (!$att) {
        jsonResponse(['success' => false, 'message' => 'Active clock-in session required'], 404);
    }

    // Check if currently on break
    $chkBreak = $pdo->prepare("SELECT id, start_time FROM breaks WHERE attendance_id = ? AND end_time IS NULL LIMIT 1");
    $chkBreak->execute([$attendanceId]);
    $activeBreak = $chkBreak->fetch();

    if ($activeBreak) {
        // End break
        $now = date('Y-m-d H:i:s');
        $duration = max(1, (int)round((time() - strtotime($activeBreak['start_time'])) / 60));
        $pdo->prepare("UPDATE breaks SET end_time = ?, duration_minutes = ? WHERE id = ?")
            ->execute([$now, $duration, $activeBreak['id']]);

        jsonResponse([
            'success' => true,
            'message' => "Break ended. Total break duration: $duration minutes. Welcome back on duty!",
            'is_on_break' => false
        ]);
    } else {
        // Start break
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO breaks (attendance_id, employee_id, break_type, start_time) VALUES (?, ?, ?, ?)")
            ->execute([$attendanceId, $att['employee_id'], $breakType, $now]);

        jsonResponse([
            'success' => true,
            'message' => "Break started ($breakType). Recorded at " . date('H:i', strtotime($now)),
            'is_on_break' => true,
            'break_type' => $breakType
        ]);
    }
}

function handleManualAdjustment(PDO $pdo, array $user): void {
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized: Only managers can make manual attendance adjustments'], 403);
    }

    $data = getRequestData();
    $id = (int)($data['id'] ?? 0);
    $clockIn = trim($data['clock_in'] ?? '');
    $clockOut = !empty($data['clock_out']) ? trim($data['clock_out']) : null;
    $status = $data['status'] ?? 'manual_adjustment';
    $regularHours = (float)($data['regular_hours'] ?? 8.0);
    $overtimeHours = (float)($data['overtime_hours'] ?? 0.0);
    $nightHours = (float)($data['night_hours'] ?? 0.0);
    $notes = trim($data['notes'] ?? 'Manager override');

    if (!$id || empty($clockIn)) {
        jsonResponse(['success' => false, 'message' => 'Attendance ID and Clock-in time are required'], 422);
    }

    $upd = $pdo->prepare("
        UPDATE attendance SET
            clock_in = ?, clock_out = ?, status = ?, regular_hours = ?,
            overtime_hours = ?, night_hours = ?, notes = ?, verified_by = ?
        WHERE id = ?
    ");
    $upd->execute([$clockIn, $clockOut, $status, $regularHours, $overtimeHours, $nightHours, $notes, (int)$user['id'], $id]);

    logAudit($pdo, (int)$user['id'], 'ATTENDANCE_MANUAL_ADJUST', 'attendance', $id, [
        'clock_in' => $clockIn,
        'clock_out' => $clockOut,
        'status' => $status,
        'notes' => $notes
    ]);

    jsonResponse(['success' => true, 'message' => 'Attendance record adjusted successfully with audit verification']);
}

function getEmployeeDutyStatus(PDO $pdo): void {
    $employeeId = (int)($_GET['employee_id'] ?? 0);
    if (!$employeeId) {
        jsonResponse(['success' => false, 'message' => 'Missing employee ID'], 422);
    }

    $stmt = $pdo->prepare("
        SELECT a.*, b_curr.id as active_break_id, b_curr.break_type as active_break_type, b_curr.start_time as break_start
        FROM attendance a
        LEFT JOIN breaks b_curr ON b_curr.attendance_id = a.id AND b_curr.end_time IS NULL
        WHERE a.employee_id = ? AND a.clock_out IS NULL
        LIMIT 1
    ");
    $stmt->execute([$employeeId]);
    $record = $stmt->fetch();

    jsonResponse([
        'success' => true,
        'is_clocked_in' => (bool)$record,
        'record' => $record
    ]);
}
