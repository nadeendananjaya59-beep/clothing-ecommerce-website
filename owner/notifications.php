<?php

/**
 * Notification Center - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications_helper.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// ─── Sync Notifications with DB events ───────────────────
sync_notifications($conn, $shop_id);

// ─── Handle Post Actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'mark_read' && isset($_POST['notification_id'])) {
        $n_id = (int)$_POST['notification_id'];
        mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE notification_id = $n_id AND shop_id = $shop_id");
        set_flash('success', 'Notification marked as read.');
    } elseif ($action === 'delete' && isset($_POST['notification_id'])) {
        $n_id = (int)$_POST['notification_id'];
        mysqli_query($conn, "UPDATE notifications SET is_deleted = 1 WHERE notification_id = $n_id AND shop_id = $shop_id");
        set_flash('success', 'Notification deleted.');
    } elseif ($action === 'mark_all_read') {
        mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE shop_id = $shop_id AND is_deleted = 0");
        set_flash('success', 'All notifications marked as read.');
    }
    header('Location: notifications.php');
    exit();
}

// ─── Fetch Active Notifications ──────────────────────────
$f_type = trim($_GET['type'] ?? '');
$where = ["shop_id = $shop_id", "is_deleted = 0"];
if ($f_type !== '') {
    $type_esc = mysqli_real_escape_string($conn, $f_type);
    $where[] = "type = '$type_esc'";
}
$where_sql = implode(' AND ', $where);

$notif_res = mysqli_query($conn, "SELECT * FROM notifications WHERE $where_sql ORDER BY created_at DESC");
$notifications = [];
if ($notif_res) {
    while ($row = mysqli_fetch_assoc($notif_res)) {
        $notifications[] = $row;
    }
}

$unread_count = get_unread_notifications_count($conn, $shop_id);

// Helper for type color styles
function type_class($type)
{
    return match ($type) {
        'Low Stock'       => 'nt-lowstock',
        'New Order'       => 'nt-order',
        'Pending Payment' => 'nt-payment',
        'New Feedback'    => 'nt-feedback',
        default           => ''
    };
}

// Helper for type icons
function type_icon($type)
{
    return match ($type) {
        'Low Stock'       => 'fas fa-triangle-exclamation',
        'New Order'       => 'fas fa-bag-shopping',
        'Pending Payment' => 'fas fa-credit-card',
        'New Feedback'    => 'fas fa-comments',
        default           => 'fas fa-bell'
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Center | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        /* Sidebar/Tab layout */
        .notif-layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 1.5rem;
        }

        @media(max-width: 768px) {
            .notif-layout {
                grid-template-columns: 1fr;
            }
        }

        .notif-sidebar {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1rem;
            height: fit-content;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .sidebar-menu-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all var(--transition);
        }

        .sidebar-menu-item:hover,
        .sidebar-menu-item.active {
            background: rgba(108, 99, 255, 0.1);
            color: var(--primary-light);
        }

        .sidebar-menu-item i {
            margin-right: 0.5rem;
            width: 18px;
        }

        /* Notification List cards */
        .notif-list-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .notif-item {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.25rem;
            display: flex;
            gap: 1.25rem;
            position: relative;
            align-items: flex-start;
            transition: all var(--transition);
        }

        .notif-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
        }

        .notif-item.unread {
            border-left: 4px solid var(--primary);
            background: rgba(108, 99, 255, 0.03);
        }

        .notif-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .nt-lowstock {
            background: rgba(239, 68, 68, 0.12);
            color: #f87171;
        }

        .nt-order {
            background: rgba(108, 99, 255, 0.12);
            color: #a78bfa;
        }

        .nt-payment {
            background: rgba(59, 130, 246, 0.12);
            color: #60a5fa;
        }

        .nt-feedback {
            background: rgba(16, 185, 129, 0.12);
            color: #34d399;
        }

        .notif-content {
            flex: 1;
        }

        .notif-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .notif-message {
            font-size: 0.83rem;
            color: var(--text-secondary);
            line-height: 1.4;
            margin-bottom: 0.5rem;
        }

        .notif-time {
            font-size: 0.72rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .notif-actions {
            display: flex;
            gap: 0.4rem;
            flex-shrink: 0;
        }

        .notif-btn {
            background: transparent;
            border: 1px solid var(--border-light);
            border-radius: 6px;
            color: var(--text-secondary);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all var(--transition);
        }

        .notif-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .notif-btn.delete-btn:hover {
            border-color: #ef4444;
            color: #ef4444;
        }
    </style>
</head>

<body>

    <!-- ═══ TOPBAR ═══ -->
    <div class="topbar">
        <a href="dashboard.php" class="topbar-brand">
            <div class="t-icon">🏪</div>
            <span><?= htmlspecialchars($shop_name) ?></span>
        </a>
        <div class="topbar-right">
            <!-- Real-time Bell Indicator -->
            <a href="notifications.php" class="notif-bell" style="position:relative; margin-right:1rem; color:var(--text-secondary); text-decoration:none; font-size:1.15rem;">
                <i class="fas fa-bell"></i>
                <span class="notif-badge" id="topBarNotifCount" style="position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; font-size:0.6rem; font-weight:700; padding:0.15rem 0.35rem; border-radius:10px; display:inline-block; line-height:1;"><?= $unread_count ?></span>
            </a>

            <div class="user-pill">
                <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
                <span><?= htmlspecialchars($owner_name) ?></span>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <div class="dashboard-wrapper">
        <div class="dashboard-main">

            <!-- Header -->
            <div class="dashboard-header" style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:1rem;">
                <div>
                    <h1>🔔 Notification Center</h1>
                    <p>Monitor real-time system alerts, stock deficits, order requests, and customer reviews.</p>
                </div>
                <?php if ($unread_count > 0): ?>
                    <form method="POST" action="notifications.php" style="margin-bottom:0.5rem;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-outline" style="font-size:0.8rem; padding:0.6rem 1rem;"><i class="fas fa-check-double"></i> Mark All as Read</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Display Flash Messages -->
            <?php if ($flash = get_flash()): ?>
                <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem; padding:1rem; border-radius:8px; background:rgba(108,99,255,0.15); color:var(--text-primary); border:1px solid var(--primary);">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Layout -->
            <div class="notif-layout">

                <!-- Sidebar filters -->
                <div class="notif-sidebar">
                    <div class="sidebar-menu">
                        <a href="notifications.php" class="sidebar-menu-item <?= $f_type === '' ? 'active' : '' ?>">
                            <span><i class="fas fa-border-all"></i> All Notifications</span>
                            <span class="badge" style="background:rgba(255,255,255,.05);"><?= count($notifications) ?></span>
                        </a>
                        <a href="notifications.php?type=Low+Stock" class="sidebar-menu-item <?= $f_type === 'Low Stock' ? 'active' : '' ?>">
                            <span><i class="fas fa-triangle-exclamation" style="color:#f87171;"></i> Low Stock</span>
                        </a>
                        <a href="notifications.php?type=New+Order" class="sidebar-menu-item <?= $f_type === 'New Order' ? 'active' : '' ?>">
                            <span><i class="fas fa-bag-shopping" style="color:#a78bfa;"></i> New Orders</span>
                        </a>
                        <a href="notifications.php?type=Pending+Payment" class="sidebar-menu-item <?= $f_type === 'Pending Payment' ? 'active' : '' ?>">
                            <span><i class="fas fa-credit-card" style="color:#60a5fa;"></i> Pending Payments</span>
                        </a>
                        <a href="notifications.php?type=New+Feedback" class="sidebar-menu-item <?= $f_type === 'New Feedback' ? 'active' : '' ?>">
                            <span><i class="fas fa-comments" style="color:#34d399;"></i> Product Reviews</span>
                        </a>
                    </div>
                </div>

                <!-- Main notif list -->
                <div class="notif-list-container">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $n): ?>
                            <div class="notif-item <?= $n['is_read'] == 0 ? 'unread' : '' ?>">
                                <div class="notif-avatar <?= type_class($n['type']) ?>">
                                    <i class="<?= type_icon($n['type']) ?>"></i>
                                </div>
                                <div class="notif-content">
                                    <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                                    <div class="notif-message"><?= htmlspecialchars($n['message']) ?></div>
                                    <div class="notif-time"><i class="far fa-clock"></i> <?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
                                </div>
                                <div class="notif-actions">
                                    <?php if ($n['is_read'] == 0): ?>
                                        <form method="POST" action="notifications.php" style="display:inline;">
                                            <input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>">
                                            <input type="hidden" name="action" value="mark_read">
                                            <button type="submit" class="notif-btn" title="Mark as read"><i class="fas fa-check"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="notifications.php" style="display:inline;">
                                        <input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="notif-btn delete-btn" title="Delete"><i class="fas fa-trash-can"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="background:var(--bg-card); border:1px solid var(--border-light); border-radius:var(--radius); padding:5rem 2rem; text-align:center; color:var(--text-muted);">
                            <i class="fas fa-bell-slash" style="font-size:3rem; margin-bottom:1rem; display:block;"></i>
                            <p>No active notifications available under this filter group.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Back to Dashboard -->
            <div style="margin-top: 2rem;">
                <a href="dashboard.php" class="btn-reset" style="display:inline-flex;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

        </div>
    </div>

    <script>
        // Real-time Notification Count Polling (dynamic intervals)
        function pollUnreadNotifications() {
            fetch('api/get_notifications_count.php')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const count = parseInt(data.unread_count);
                        const countBadge = document.getElementById('topBarNotifCount');
                        if (countBadge) {
                            if (count > 0) {
                                countBadge.textContent = count;
                                countBadge.style.display = 'inline-block';
                            } else {
                                countBadge.style.display = 'none';
                            }
                        }
                    }
                })
                .catch(err => console.error("Notification polling error: ", err));
        }

        // Poll every 10 seconds
        setInterval(pollUnreadNotifications, 10000);
    </script>
</body>

</html>