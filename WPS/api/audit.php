<?php
/**
 * 24/7 - Compliance & Audit Trail API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth(['admin', 'manager', 'supervisor']);

$search = trim($_GET['search'] ?? '');
$entityType = trim($_GET['entity_type'] ?? '');
$limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));

$sql = "
    SELECT a.*, u.username, u.role,
           CONCAT(e.first_name, ' ', e.last_name) as employee_name, e.employee_code,
           b.name as branch_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN employees e ON u.employee_id = e.id
    LEFT JOIN branches b ON a.branch_id = b.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (a.action LIKE ? OR a.details LIKE ? OR u.username LIKE ?)";
    $w = "%$search%";
    $params = array_merge($params, [$w, $w, $w]);
}
if (!empty($entityType) && $entityType !== 'all') {
    $sql .= " AND a.entity_type = ?";
    $params[] = $entityType;
}

$sql .= " ORDER BY a.created_at DESC LIMIT $limit";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

jsonResponse(['success' => true, 'audit_logs' => $logs, 'count' => count($logs)]);
