<?php
/**
 * Polling API for Real-time Notifications Count
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/notifications_helper.php';

// Return json
header('Content-Type: application/json');

if (!is_owner_logged_in()) {
    echo json_encode(['unread_count' => 0, 'status' => 'unauthorized']);
    exit();
}

$shop_id = $_SESSION['owner_shop_id'] ?? 0;

// Sync first to get the latest alerts
sync_notifications($conn, $shop_id);

$unread = get_unread_notifications_count($conn, $shop_id);

echo json_encode([
    'unread_count' => $unread,
    'status'       => 'success'
]);
exit();
?>
