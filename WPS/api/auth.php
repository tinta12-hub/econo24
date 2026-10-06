<?php
/**
 * 24/7 - Authentication & User Session API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'POST' ? 'login' : 'me');

// Route Actions
switch ($action) {
    case 'login':
        handleLogin($pdo);
        break;

    case 'demo_login':
        handleDemoLogin($pdo);
        break;

    case 'me':
        handleMe($pdo);
        break;

    case 'logout':
        handleLogout($pdo);
        break;

    case 'users':
        handleListUsers($pdo);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid auth action'], 400);
}

function handleLogin(PDO $pdo): void {
    $data = getRequestData();
    $login = trim($data['login'] ?? $data['email'] ?? $data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($login) || empty($password)) {
        jsonResponse(['success' => false, 'message' => 'Please provide username/email and password'], 422);
    }

    $stmt = $pdo->prepare("
        SELECT u.*, e.first_name, e.last_name, e.role_title, e.branch_id, e.department_id, e.employee_code, e.avatar_color,
               b.name as branch_name, d.name as department_name
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.id
        LEFT JOIN branches b ON e.branch_id = b.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE (u.email = ? OR u.username = ?) AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['success' => false, 'message' => 'Invalid credentials or inactive account'], 401);
    }

    // Update last login
    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $updateStmt->execute([$user['id']]);

    // Strip password hash from session & response
    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    logAudit($pdo, (int)$user['id'], 'USER_LOGIN', 'users', (int)$user['id'], ['username' => $user['username']], $user['branch_id'] ? (int)$user['branch_id'] : null);

    jsonResponse([
        'success' => true,
        'message' => 'Login successful',
        'user' => $user
    ]);
}

function handleDemoLogin(PDO $pdo): void {
    $data = getRequestData();
    $role = $data['role'] ?? 'admin';

    $roleMap = [
        'admin'      => 'admin@247ops.com',
        'manager'    => 'manager.downtown@247ops.com',
        'supervisor' => 'supervisor.night@247ops.com',
        'staff'      => 'marcus.tech@247ops.com'
    ];

    $email = $roleMap[$role] ?? $roleMap['admin'];

    $stmt = $pdo->prepare("
        SELECT u.*, e.first_name, e.last_name, e.role_title, e.branch_id, e.department_id, e.employee_code, e.avatar_color,
               b.name as branch_name, d.name as department_name
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.id
        LEFT JOIN branches b ON e.branch_id = b.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE u.email = ? AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['success' => false, 'message' => "Demo user for role '$role' not found"], 404);
    }

    // Update last login
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    logAudit($pdo, (int)$user['id'], 'DEMO_LOGIN', 'users', (int)$user['id'], ['role' => $user['role']], $user['branch_id'] ? (int)$user['branch_id'] : null);

    jsonResponse([
        'success' => true,
        'message' => "Logged in as {$user['role_title']} ({$user['role']})",
        'user' => $user
    ]);
}

function handleMe(PDO $pdo): void {
    $user = getAuthUser();
    if (!$user) {
        // Return null user without error to let frontend decide to show login
        jsonResponse([
            'success' => true,
            'authenticated' => false,
            'user' => null
        ]);
    }

    // Refresh user data from DB
    $stmt = $pdo->prepare("
        SELECT u.id, u.employee_id, u.username, u.email, u.role, u.status, u.last_login, u.avatar_path,
               e.first_name, e.last_name, e.role_title, e.branch_id, e.department_id, e.employee_code, e.avatar_color,
               COALESCE(u.avatar_path, e.avatar_path) as avatar_url,
               b.name as branch_name, d.name as department_name
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.id
        LEFT JOIN branches b ON e.branch_id = b.id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE u.id = ? AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $fresh = $stmt->fetch();

    if (!$fresh) {
        unset($_SESSION['user']);
        jsonResponse(['success' => true, 'authenticated' => false, 'user' => null]);
    }

    $_SESSION['user'] = $fresh;
    jsonResponse([
        'success' => true,
        'authenticated' => true,
        'user' => $fresh
    ]);
}

function handleLogout(PDO $pdo): void {
    $user = getAuthUser();
    if ($user) {
        logAudit($pdo, (int)$user['id'], 'USER_LOGOUT', 'users', (int)$user['id'], null, $user['branch_id'] ? (int)$user['branch_id'] : null);
    }
    $_SESSION = [];
    if (session_id() !== '' || headers_sent() === false) {
        session_destroy();
    }
    jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
}

function handleListUsers(PDO $pdo): void {
    requireAuth(['admin', 'manager']);
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.email, u.role, u.status, u.last_login,
               e.first_name, e.last_name, e.role_title, b.name as branch_name
        FROM users u
        LEFT JOIN employees e ON u.employee_id = e.id
        LEFT JOIN branches b ON e.branch_id = b.id
        ORDER BY u.id ASC
    ");
    jsonResponse(['success' => true, 'users' => $stmt->fetchAll()]);
}
