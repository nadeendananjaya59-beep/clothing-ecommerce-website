<?php
/**
 * Notifications Helper Utilities
 */
require_once __DIR__ . '/../db/config.php';

// Ensure notifications table exists
function init_notifications_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS `notifications` (
      `notification_id` int(11) NOT NULL AUTO_INCREMENT,
      `shop_id` int(11) NOT NULL,
      `type` varchar(50) NOT NULL,
      `source_id` int(11) NOT NULL,
      `title` varchar(255) NOT NULL,
      `message` text NOT NULL,
      `is_read` tinyint(1) DEFAULT 0,
      `is_deleted` tinyint(1) DEFAULT 0,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`notification_id`),
      UNIQUE KEY `unique_shop_source` (`shop_id`, `type`, `source_id`),
      FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $sql);
}

// Sync events in database to notifications table
function sync_notifications($conn, $shop_id) {
    init_notifications_table($conn);
    $shop_id = (int)$shop_id;

    // 1. Low Stock Alerts
    $low_stock_sql = "INSERT IGNORE INTO notifications (shop_id, type, source_id, title, message)
                      SELECT p.shop_id, 'Low Stock', p.product_id,
                             CONCAT('Low Stock Alert: ', p.product_name),
                             CONCAT('Stock has dropped to ', p.stock_qty, ' (Reorder level: ', p.reorder_level, ')')
                      FROM products p
                      LEFT JOIN notifications n ON n.type = 'Low Stock' AND n.source_id = p.product_id AND n.shop_id = p.shop_id
                      WHERE p.shop_id = $shop_id AND p.stock_qty <= p.reorder_level AND n.notification_id IS NULL";
    mysqli_query($conn, $low_stock_sql);

    // 2. New Orders Alerts
    $new_orders_sql = "INSERT IGNORE INTO notifications (shop_id, type, source_id, title, message, created_at)
                       SELECT o.shop_id, 'New Order', o.order_id,
                              CONCAT('New Order #', o.order_id),
                              CONCAT('A new order of LKR ', FORMAT(o.total_amount, 2), ' has been placed.'),
                              o.order_date
                       FROM orders o
                       LEFT JOIN notifications n ON n.type = 'New Order' AND n.source_id = o.order_id AND n.shop_id = o.shop_id
                       WHERE o.shop_id = $shop_id AND o.order_status = 'Pending' AND n.notification_id IS NULL";
    mysqli_query($conn, $new_orders_sql);

    // 3. Pending Payment Alerts
    $pending_payments_sql = "INSERT IGNORE INTO notifications (shop_id, type, source_id, title, message, created_at)
                             SELECT o.shop_id, 'Pending Payment', py.payment_id,
                                    CONCAT('Pending Payment for Order #', o.order_id),
                                    CONCAT('A payment of LKR ', FORMAT(py.amount, 2), ' via ', pm.method_name, ' is pending approval.'),
                                    py.payment_date
                             FROM payments py
                             JOIN orders o ON py.order_id = o.order_id
                             JOIN payment_methods pm ON py.method_id = pm.method_id
                             LEFT JOIN notifications n ON n.type = 'Pending Payment' AND n.source_id = py.payment_id AND n.shop_id = o.shop_id
                             WHERE o.shop_id = $shop_id AND py.payment_status = 'Pending' AND n.notification_id IS NULL";
    mysqli_query($conn, $pending_payments_sql);

    // 4. New Feedback Alerts
    $new_feedback_sql = "INSERT IGNORE INTO notifications (shop_id, type, source_id, title, message, created_at)
                         SELECT p.shop_id, 'New Feedback', uf.feedback_id,
                                CONCAT('New Review on ', p.product_name),
                                CONCAT('Customer left a ', uf.rating, '-star review: \"', SUBSTRING(uf.feedback_text, 1, 40), '...\"'),
                                uf.feedback_date
                         FROM user_feedback uf
                         JOIN products p ON uf.product_id = p.product_id
                         LEFT JOIN notifications n ON n.type = 'New Feedback' AND n.source_id = uf.feedback_id AND n.shop_id = p.shop_id
                         WHERE p.shop_id = $shop_id AND uf.status = 'Pending' AND n.notification_id IS NULL";
    mysqli_query($conn, $new_feedback_sql);
}

// Get count of unread notifications
function get_unread_notifications_count($conn, $shop_id) {
    init_notifications_table($conn);
    $shop_id = (int)$shop_id;
    $res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM notifications WHERE shop_id = $shop_id AND is_read = 0 AND is_deleted = 0");
    if ($res) {
        return (int)mysqli_fetch_assoc($res)['c'];
    }
    return 0;
}
?>
