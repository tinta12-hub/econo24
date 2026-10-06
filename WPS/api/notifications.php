<?php
/**
 * 24/7 - Notifications & Operational Alerts API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'GET' ? 'list' : 'mark_read');

switch ($action) {
    case 'list':
        $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== 'all' ? (int)$_GET['branch_id'] : null;
        $sql = "
            SELECT n.*, b.name as branch_name
            FROM notifications n
            LEFT JOIN branches b ON n.branch_id = b.id
            WHERE (n.user_id = ? OR n.user_id IS NULL)
        ";
        $params = [$user['id']];
        if ($branchId !== null) {
            $sql .= " AND (n.branch_id = ? OR n.branch_id IS NULL)";
            $params[] = $branchId;
        }
        $sql .= " ORDER BY n.created_at DESC LIMIT 30";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll();

        $unreadCount = count(array_filter($list, fn($item) => (int)$item['is_read'] === 0));

        jsonResponse([
            'success' => true,
            'notifications' => $list,
            'unread_count' => $unreadCount
        ]);
        break;

    case 'mark_read':
        $data = getRequestData();
        $id = isset($data['id']) ? (int)$data['id'] : null;
        if ($id) {
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL)")->execute([$user['id']]);
        }
        jsonResponse(['success' => true, 'message' => 'Notifications marked as read']);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid notification action'], 400);
}
