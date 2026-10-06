<?php
/**
 * 24/7 - Core Helper Functions & Utilities
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Return JSON response and terminate
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Get sanitized request payload (handles JSON body and form-data)
 */
function getRequestData(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
    return $_POST;
}

/**
 * Get current authenticated user session
 */
function getAuthUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Require authenticated user, optionally checking role
 */
function requireAuth(?array $allowedRoles = null): array {
    $user = getAuthUser();
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized: Please log in'], 401);
    }
    if ($allowedRoles !== null && !in_array($user['role'], $allowedRoles, true)) {
        jsonResponse(['success' => false, 'message' => 'Forbidden: Insufficient privileges'], 403);
    }
    return $user;
}

/**
 * Robust 24-hour shift duration calculation (hours)
 * Handles overnight shifts (e.g. 22:00 to 06:00 = 8 hours)
 */
function calculateShiftDuration(string $startTime, string $endTime, bool $isOvernight = false): float {
    $startSec = strtotime("1970-01-01 $startTime");
    $endSec = strtotime("1970-01-01 $endTime");

    if ($endSec <= $startSec || $isOvernight) {
        $endSec = strtotime("1970-01-02 $endTime");
    }

    $diffSec = $endSec - $startSec;
    return round($diffSec / 3600, 2);
}

/**
 * Compute start and end datetimes for a shift given base date and times
 */
function getShiftDatetimes(string $shiftDate, string $startTime, string $endTime, bool $isOvernight = false): array {
    $startDatetime = "$shiftDate $startTime";
    if ($isOvernight || strtotime("$shiftDate $endTime") <= strtotime("$shiftDate $startTime")) {
        $nextDay = date('Y-m-d', strtotime("$shiftDate +1 day"));
        $endDatetime = "$nextDay $endTime";
    } else {
        $endDatetime = "$shiftDate $endTime";
    }
    return [
        'start' => $startDatetime,
        'end' => $endDatetime
    ];
}

/**
 * Log action to audit_logs table
 */
function logAudit(
    PDO $pdo,
    ?int $userId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    $details = null,
    ?int $branchId = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, branch_id, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detailsJson = is_string($details) ? $details : json_encode($details);
        $stmt->execute([$userId, $action, $entityType, $entityId, $branchId, $detailsJson, $ip]);
    } catch (Exception $e) {
        // Silently log or ignore audit failure to not break primary transaction
        error_log("Audit log failed: " . $e->getMessage());
    }
}

/**
 * Create a system notification
 */
function createNotification(
    PDO $pdo,
    string $title,
    string $message,
    string $severity = 'info',
    ?int $branchId = null,
    ?string $linkModule = null,
    ?int $linkId = null,
    ?int $userId = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (branch_id, user_id, type, title, message, severity, link_module, link_id, is_read, created_at)
            VALUES (?, ?, 'system', ?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$branchId, $userId, $title, $message, $severity, $linkModule, $linkId]);
    } catch (Exception $e) {
        error_log("Notification failed: " . $e->getMessage());
    }
}
