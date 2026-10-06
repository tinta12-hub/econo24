<?php
/**
 * 24/7 - System Settings & Operational Rules API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$pdo = Database::getConnection();
$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $pdo->query("SELECT * FROM system_settings ORDER BY id ASC")->fetchAll();
    $settingsMap = [];
    foreach ($rows as $r) {
        $settingsMap[$r['setting_key']] = [
            'value' => $r['setting_value'],
            'description' => $r['description'],
            'updated_at' => $r['updated_at']
        ];
    }

    $biz = $pdo->query("SELECT * FROM businesses LIMIT 1")->fetch();

    jsonResponse([
        'success' => true,
        'settings' => $settingsMap,
        'business' => $biz
    ]);
} elseif ($method === 'POST') {
    if (!in_array($user['role'], ['admin', 'manager'], true)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized to update system settings'], 403);
    }

    $data = getRequestData();
    $settings = $data['settings'] ?? [];
    $business = $data['business'] ?? [];

    $updStmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    foreach ($settings as $key => $val) {
        $updStmt->execute([(string)$val, (string)$key]);
    }

    if (!empty($business)) {
        $bName = trim($business['name'] ?? '');
        $bTag = trim($business['tagline'] ?? '');
        $bModel = trim($business['operating_model'] ?? '24/7/365 Continuous');
        $bCur = trim($business['currency'] ?? 'ZMW');
        $bPhone = trim($business['phone'] ?? '');
        $bEmail = trim($business['email'] ?? '');

        $bUpd = $pdo->prepare("
            UPDATE businesses SET
                name = COALESCE(NULLIF(?, ''), name),
                tagline = COALESCE(NULLIF(?, ''), tagline),
                operating_model = COALESCE(NULLIF(?, ''), operating_model),
                currency = COALESCE(NULLIF(?, ''), currency),
                phone = COALESCE(NULLIF(?, ''), phone),
                email = COALESCE(NULLIF(?, ''), email)
            WHERE id = 1
        ");
        $bUpd->execute([$bName, $bTag, $bModel, $bCur, $bPhone, $bEmail]);
    }

    logAudit($pdo, (int)$user['id'], 'SETTINGS_UPDATE', 'system_settings', 1, $settings);

    jsonResponse(['success' => true, 'message' => 'System settings and business configuration updated successfully']);
} else {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}
