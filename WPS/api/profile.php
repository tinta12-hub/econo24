<?php
/**
 * 24/7 - User Profile & Personal Duty Operations API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'GET' ? 'get_profile' : '');

switch ($action) {
    case 'get_profile':
        getProfile($pdo, $user);
        break;

    case 'update_profile':
        updateProfile($pdo, $user);
        break;

    case 'change_password':
        changePassword($pdo, $user);
        break;

    case 'upload_avatar':
        uploadAvatar($pdo, $user);
        break;

    case 'my_clock_in':
        personalClockIn($pdo, $user);
        break;

    case 'my_clock_out':
        personalClockOut($pdo, $user);
        break;

    case 'my_break_toggle':
        personalBreakToggle($pdo, $user);
        break;

    case 'my_duty_status':
        getMyDutyStatus($pdo, $user);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid profile action'], 400);
}

function getProfile(PDO $pdo, array $user): void {
    $stmt = $pdo->prepare("
        SELECT u.id as user_id, u.username, u.email, u.role, u.status, u.last_login, u.avatar_path as user_avatar,
               e.id as employee_id, e.employee_code, e.first_name, e.last_name, e.phone, e.role_title,
               e.employment_type, e.shift_preference, e.hourly_rate, e.hire_date, e.emergency_contact,
               e.avatar_color, e.avatar_path as emp_avatar,
               b.name as branch_name, b.code as branch_code, d.name as department_name
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.id
        LEFT JOIN branches b ON e.branch_id = b.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        jsonResponse(['success' => false, 'message' => 'Profile not found'], 404);
    }

    $empId = $profile['employee_id'];
    $liveDuty = null;
    $recentShifts = [];
    $recentAttendance = [];

    if ($empId) {
        // Live Duty Status
        $dutyStmt = $pdo->prepare("
            SELECT a.*, st.name as shift_name, st.is_overnight,
                   b_curr.id as active_break_id, b_curr.break_type as active_break_type, b_curr.start_time as break_start
            FROM attendance a
            LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
            LEFT JOIN shift_templates st ON sa.shift_template_id = st.id
            LEFT JOIN breaks b_curr ON b_curr.attendance_id = a.id AND b_curr.end_time IS NULL
            WHERE a.employee_id = ? AND a.clock_out IS NULL
            ORDER BY a.clock_in DESC LIMIT 1
        ");
        $dutyStmt->execute([$empId]);
        $liveDuty = $dutyStmt->fetch();

        if ($liveDuty) {
            $inTs = strtotime($liveDuty['clock_in']);
            $diff = max(0, time() - $inTs);
            $liveDuty['time_worked'] = sprintf('%dh %02dm', floor($diff / 3600), floor(($diff % 3600) / 60));
        }

        // Recent Shifts
        $shiftStmt = $pdo->prepare("
            SELECT sa.*, st.name as shift_name, st.start_time, st.end_time, st.is_overnight
            FROM shift_assignments sa
            JOIN shift_templates st ON sa.shift_template_id = st.id
            WHERE sa.employee_id = ?
            ORDER BY sa.start_datetime DESC
            LIMIT 5
        ");
        $shiftStmt->execute([$empId]);
        $recentShifts = $shiftStmt->fetchAll();

        // Recent Attendance
        $attStmt = $pdo->prepare("
            SELECT a.*, st.name as shift_name
            FROM attendance a
            LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
            LEFT JOIN shift_templates st ON sa.shift_template_id = st.id
            WHERE a.employee_id = ?
            ORDER BY a.clock_in DESC
            LIMIT 5
        ");
        $attStmt->execute([$empId]);
        $recentAttendance = $attStmt->fetchAll();
    }

    $profile['avatar_url'] = $profile['user_avatar'] ?: $profile['emp_avatar'] ?: null;

    jsonResponse([
        'success' => true,
        'profile' => $profile,
        'live_duty' => $liveDuty,
        'is_clocked_in' => !empty($liveDuty),
        'recent_shifts' => $recentShifts,
        'recent_attendance' => $recentAttendance
    ]);
}

function updateProfile(PDO $pdo, array $user): void {
    $data = getRequestData();
    $firstName = trim($data['first_name'] ?? '');
    $lastName = trim($data['last_name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $emergencyContact = trim($data['emergency_contact'] ?? '');
    $shiftPref = in_array($data['shift_preference'] ?? '', ['any', 'morning', 'afternoon', 'night'], true) ? $data['shift_preference'] : 'any';

    if (empty($firstName) || empty($lastName)) {
        jsonResponse(['success' => false, 'message' => 'First Name and Last Name are required'], 422);
    }

    if (!empty($user['employee_id'])) {
        $stmt = $pdo->prepare("
            UPDATE employees SET
                first_name = ?, last_name = ?, phone = ?, emergency_contact = ?, shift_preference = ?
            WHERE id = ?
        ");
        $stmt->execute([$firstName, $lastName, $phone, $emergencyContact, $shiftPref, $user['employee_id']]);
    }

    // Refresh session data
    $_SESSION['user']['first_name'] = $firstName;
    $_SESSION['user']['last_name'] = $lastName;

    logAudit($pdo, (int)$user['id'], 'PROFILE_UPDATE', 'users', (int)$user['id'], [
        'name' => "$firstName $lastName",
        'phone' => $phone
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Profile information updated successfully',
        'first_name' => $firstName,
        'last_name' => $lastName
    ]);
}

function changePassword(PDO $pdo, array $user): void {
    $data = getRequestData();
    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword)) {
        jsonResponse(['success' => false, 'message' => 'Current password and new password are required'], 422);
    }

    if (strlen($newPassword) < 6) {
        jsonResponse(['success' => false, 'message' => 'New password must be at least 6 characters long'], 422);
    }

    if ($newPassword !== $confirmPassword) {
        jsonResponse(['success' => false, 'message' => 'New password and confirmation do not match'], 422);
    }

    // Verify current password from database
    $chkStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $chkStmt->execute([$user['id']]);
    $currentHash = $chkStmt->fetchColumn();

    if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
        jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 401);
    }

    // Hash and update
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $upd->execute([$newHash, $user['id']]);

    logAudit($pdo, (int)$user['id'], 'PASSWORD_CHANGE', 'users', (int)$user['id']);

    jsonResponse([
        'success' => true,
        'message' => 'Your password has been changed successfully. Please keep it secure!'
    ]);
}

function uploadAvatar(PDO $pdo, array $user): void {
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'Please select an image file to upload'], 422);
    }

    $file = $_FILES['avatar'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['size'] > $maxSize) {
        jsonResponse(['success' => false, 'message' => 'File size exceeds 5MB limit'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowedTypes, true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid image format. Allowed formats: JPG, PNG, WebP, GIF'], 422);
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg'
    };

    $filename = sprintf('avatar_%d_%s.%s', $user['id'], bin2hex(random_bytes(6)), $ext);
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $targetPath = $uploadDir . $filename;
    $relativeUrl = 'uploads/avatars/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        jsonResponse(['success' => false, 'message' => 'Failed to save uploaded image'], 500);
    }

    // Update database
    $pdo->prepare("UPDATE users SET avatar_path = ? WHERE id = ?")->execute([$relativeUrl, $user['id']]);
    if (!empty($user['employee_id'])) {
        $pdo->prepare("UPDATE employees SET avatar_path = ? WHERE id = ?")->execute([$relativeUrl, $user['employee_id']]);
    }

    $_SESSION['user']['avatar_path'] = $relativeUrl;

    logAudit($pdo, (int)$user['id'], 'AVATAR_UPLOAD', 'users', (int)$user['id'], ['path' => $relativeUrl]);

    jsonResponse([
        'success' => true,
        'message' => 'Profile picture uploaded and updated successfully!',
        'avatar_url' => $relativeUrl
    ]);
}

function getMyDutyStatus(PDO $pdo, array $user): void {
    $empId = $user['employee_id'];
    if (!$empId) {
        jsonResponse(['success' => true, 'is_clocked_in' => false, 'message' => 'No linked employee profile']);
    }

    $stmt = $pdo->prepare("
        SELECT a.*, st.name as shift_name, st.start_time, st.end_time, st.is_overnight,
               b_curr.id as active_break_id, b_curr.break_type as active_break_type, b_curr.start_time as break_start
        FROM attendance a
        LEFT JOIN shift_assignments sa ON a.shift_assignment_id = sa.id
        LEFT JOIN shift_templates st ON sa.shift_template_id = st.id
        LEFT JOIN breaks b_curr ON b_curr.attendance_id = a.id AND b_curr.end_time IS NULL
        WHERE a.employee_id = ? AND a.clock_out IS NULL
        LIMIT 1
    ");
    $stmt->execute([$empId]);
    $duty = $stmt->fetch();

    if ($duty) {
        $inTs = strtotime($duty['clock_in']);
        $diff = max(0, time() - $inTs);
        $duty['time_worked'] = sprintf('%dh %02dm', floor($diff / 3600), floor(($diff % 3600) / 60));
        $duty['clock_in_formatted'] = date('H:i:s', $inTs);
    }

    jsonResponse([
        'success' => true,
        'is_clocked_in' => !empty($duty),
        'duty' => $duty
    ]);
}

function personalClockIn(PDO $pdo, array $user): void {
    $empId = $user['employee_id'];
    if (!$empId) {
        jsonResponse(['success' => false, 'message' => 'Your user account is not linked to an employee profile. Please contact administrator.'], 403);
    }

    // Re-use logic from handleClockIn in attendance.php
    $_POST['employee_id'] = $empId;
    require_once __DIR__ . '/attendance.php';
    handleClockIn($pdo, $user);
}

function personalClockOut(PDO $pdo, array $user): void {
    $empId = $user['employee_id'];
    if (!$empId) {
        jsonResponse(['success' => false, 'message' => 'No linked employee profile.'], 403);
    }

    $_POST['employee_id'] = $empId;
    require_once __DIR__ . '/attendance.php';
    handleClockOut($pdo, $user);
}

function personalBreakToggle(PDO $pdo, array $user): void {
    $empId = $user['employee_id'];
    if (!$empId) {
        jsonResponse(['success' => false, 'message' => 'No linked employee profile.'], 403);
    }

    $chk = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND clock_out IS NULL LIMIT 1");
    $chk->execute([$empId]);
    $attendanceId = $chk->fetchColumn();

    if (!$attendanceId) {
        jsonResponse(['success' => false, 'message' => 'You must be clocked in to manage breaks'], 400);
    }

    $_POST['attendance_id'] = $attendanceId;
    $_POST['break_type'] = $_POST['break_type'] ?? 'meal';
    require_once __DIR__ . '/attendance.php';
    handleBreakToggle($pdo, $user);
}
