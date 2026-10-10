<?php

/**
 * Owner Dashboard - Full Statistics
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications_helper.php';

require_owner_login();

$owner      = get_owner_profile($conn, $_SESSION['owner_id']);
$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));
$last_login = $owner['last_login'] ? date('d M Y, h:i A', strtotime($owner['last_login'])) : 'First Login';

sync_notifications($conn, $shop_id);
$unread_count = get_unread_notifications_count($conn, $shop_id);

// ─── Fetch All Stats ──────────────────────────────────────
// 1. Total Products
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM products");
$s = mysqli_fetch_assoc($r);
$total_products = $s['c'];

// 2. Total Categories
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM categories");
$s = mysqli_fetch_assoc($r);
$total_categories = $s['c'];

// 3. Total Brands
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM brands");
$s = mysqli_fetch_assoc($r);
$total_brands = $s['c'];

// 4. Total Suppliers
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM suppliers");
$s = mysqli_fetch_assoc($r);
$total_suppliers = $s['c'];

// 5. Total Customers
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM customers");
$s = mysqli_fetch_assoc($r);
$total_customers = $s['c'];

// 6. Total Orders
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM orders");
$s = mysqli_fetch_assoc($r);
$total_orders = $s['c'];

// 7. Total Revenue (non-cancelled)
$r = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) AS c FROM orders WHERE order_status != 'Cancelled'");
$s = mysqli_fetch_assoc($r);
$total_revenue = $s['c'];

// 8. Total Feedbacks
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM user_feedback");
$s = mysqli_fetch_assoc($r);
$total_feedbacks = $s['c'];

// 9. Low Stock Products (stock_qty > 0 AND stock_qty <= reorder_level)
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM products WHERE stock_qty > 0 AND stock_qty <= reorder_level");
$s = mysqli_fetch_assoc($r);
$low_stock = $s['c'];

// 10. Out of Stock
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM products WHERE stock_qty = 0 OR status = 'Out of Stock'");
$s = mysqli_fetch_assoc($r);
$out_of_stock = $s['c'];

// 11. Pending Orders
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM orders WHERE order_status = 'Pending'");
$s = mysqli_fetch_assoc($r);
$pending_orders = $s['c'];

// 12. Pending Payments
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM payments WHERE payment_status = 'Pending'");
$s = mysqli_fetch_assoc($r);
$pending_payments = $s['c'];

// ─── Recent Orders (last 5) ──────────────────────────────
$recent_orders = [];
$ro = mysqli_query($conn, "SELECT o.order_id, c.full_name, o.total_amount, o.order_status, o.order_date
    FROM orders o JOIN customers c ON o.customer_id = c.customer_id
    ORDER BY o.order_date DESC LIMIT 5");
if ($ro) {
    while ($row = mysqli_fetch_assoc($ro)) {
        $recent_orders[] = $row;
    }
}

// ─── Low Stock Items (top 5) ─────────────────────────────
$low_stock_items = [];
$ls = mysqli_query($conn, "SELECT product_name, stock_qty, reorder_level, status FROM products
    WHERE stock_qty <= reorder_level ORDER BY stock_qty ASC LIMIT 5");
if ($ls) {
    while ($row = mysqli_fetch_assoc($ls)) {
        $low_stock_items[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        /* ── Extra Dashboard Styles ── */
        .metrics-section-title {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin: 2rem 0 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .metrics-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-light);
        }

        /* 12-card grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.2rem;
        }

        @media(max-width:1100px) {
            .metrics-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media(max-width:768px) {
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width:480px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Metric Card */
        .metric-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.4rem 1.3rem;
            position: relative;
            overflow: hidden;
            transition: all var(--transition);
            cursor: default;
        }

        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: -30px;
            right: -20px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            opacity: 0.08;
        }

        /* Color themes */
        .mc-purple {
            border-top: 3px solid #6c63ff;
        }

        .mc-purple::before {
            background: #6c63ff;
        }

        .mc-purple .mc-icon {
            background: rgba(108, 99, 255, .15);
            color: #8b84ff;
        }

        .mc-blue {
            border-top: 3px solid #3b82f6;
        }

        .mc-blue::before {
            background: #3b82f6;
        }

        .mc-blue .mc-icon {
            background: rgba(59, 130, 246, .15);
            color: #60a5fa;
        }

        .mc-green {
            border-top: 3px solid #10b981;
        }

        .mc-green::before {
            background: #10b981;
        }

        .mc-green .mc-icon {
            background: rgba(16, 185, 129, .15);
            color: #34d399;
        }

        .mc-amber {
            border-top: 3px solid #f59e0b;
        }

        .mc-amber::before {
            background: #f59e0b;
        }

        .mc-amber .mc-icon {
            background: rgba(245, 158, 11, .15);
            color: #fbbf24;
        }

        .mc-pink {
            border-top: 3px solid #ec4899;
        }

        .mc-pink::before {
            background: #ec4899;
        }

        .mc-pink .mc-icon {
            background: rgba(236, 72, 153, .15);
            color: #f472b6;
        }

        .mc-cyan {
            border-top: 3px solid #06b6d4;
        }

        .mc-cyan::before {
            background: #06b6d4;
        }

        .mc-cyan .mc-icon {
            background: rgba(6, 182, 212, .15);
            color: #22d3ee;
        }

        .mc-red {
            border-top: 3px solid #ef4444;
        }

        .mc-red::before {
            background: #ef4444;
        }

        .mc-red .mc-icon {
            background: rgba(239, 68, 68, .15);
            color: #f87171;
        }

        .mc-orange {
            border-top: 3px solid #f97316;
        }

        .mc-orange::before {
            background: #f97316;
        }

        .mc-orange .mc-icon {
            background: rgba(249, 115, 22, .15);
            color: #fb923c;
        }

        .mc-teal {
            border-top: 3px solid #14b8a6;
        }

        .mc-teal::before {
            background: #14b8a6;
        }

        .mc-teal .mc-icon {
            background: rgba(20, 184, 166, .15);
            color: #2dd4bf;
        }

        .mc-indigo {
            border-top: 3px solid #818cf8;
        }

        .mc-indigo::before {
            background: #818cf8;
        }

        .mc-indigo .mc-icon {
            background: rgba(129, 140, 248, .15);
            color: #a5b4fc;
        }

        .mc-rose {
            border-top: 3px solid #f43f5e;
        }

        .mc-rose::before {
            background: #f43f5e;
        }

        .mc-rose .mc-icon {
            background: rgba(244, 63, 94, .15);
            color: #fb7185;
        }

        .mc-violet {
            border-top: 3px solid #8b5cf6;
        }

        .mc-violet::before {
            background: #8b5cf6;
        }

        .mc-violet .mc-icon {
            background: rgba(139, 92, 246, .15);
            color: #a78bfa;
        }

        .mc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            margin-bottom: 1rem;
        }

        .mc-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .mc-value.small {
            font-size: 1.3rem;
        }

        .mc-label {
            font-size: 0.78rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .mc-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.2rem 0.55rem;
            border-radius: 20px;
        }

        .mc-badge-warn {
            background: rgba(245, 158, 11, .15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, .3);
        }

        .mc-badge-danger {
            background: rgba(239, 68, 68, .15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, .3);
        }

        /* Tables */
        .dash-table {
            width: 100%;
            border-collapse: collapse;
        }

        .dash-table th {
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 700;
            padding: 0.6rem 0.8rem;
            border-bottom: 1px solid var(--border-light);
        }

        .dash-table td {
            padding: 0.75rem 0.8rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }

        .dash-table tr:last-child td {
            border-bottom: none;
        }

        .dash-table tr:hover td {
            background: rgba(255, 255, 255, 0.025);
        }

        /* Status pill */
        .status-pill {
            display: inline-block;
            padding: 0.2rem 0.65rem;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .sp-pending {
            background: rgba(245, 158, 11, .15);
            color: #fbbf24;
        }

        .sp-processing {
            background: rgba(59, 130, 246, .15);
            color: #60a5fa;
        }

        .sp-shipped {
            background: rgba(139, 92, 246, .15);
            color: #a78bfa;
        }

        .sp-delivered {
            background: rgba(16, 185, 129, .15);
            color: #34d399;
        }

        .sp-cancelled {
            background: rgba(239, 68, 68, .15);
            color: #f87171;
        }

        /* Two-col tables layout */
        .tables-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        @media(max-width:900px) {
            .tables-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Stock bar */
        .stock-bar-wrap {
            width: 80px;
            background: var(--border-light);
            border-radius: 4px;
            height: 6px;
            display: inline-block;
            vertical-align: middle;
        }

        .stock-bar {
            height: 100%;
            border-radius: 4px;
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
                <span class="notif-badge" id="topBarNotifCount" style="position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; font-size:0.6rem; font-weight:700; padding:0.15rem 0.35rem; border-radius:10px; <?= $unread_count > 0 ? '' : 'display:none;' ?> line-height:1;"><?= $unread_count ?></span>
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

    <!-- ═══ MAIN ═══ -->
    <div class="dashboard-wrapper">
        <div class="dashboard-container">
            <?php require_once __DIR__ . '/includes/sidebar_quick_actions.php'; ?>

            <div class="dashboard-main">

                <!-- Header -->
                <div class="dashboard-header">
                    <h1>📊 Dashboard Overview</h1>
                    <p>Real-time snapshot of your shop. Last login: <strong><?= $last_login ?></strong></p>
                </div>

                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <div>
                        <h2>Hello, <?= htmlspecialchars(explode(' ', $owner_name)[0]) ?>! 👋</h2>
                        <p>Shop Owner &nbsp;·&nbsp; <?= htmlspecialchars($shop_name) ?> &nbsp;·&nbsp; Shop ID: #<?= (int)$shop_id ?></p>
                    </div>
                    <div class="welcome-emoji">🏪</div>
                </div>

                <!-- ══ Section 1: Catalogue ══ -->
                <div class="metrics-section-title"><i class="fas fa-layer-group"></i> Catalogue</div>
                <div class="metrics-grid">

                    <div class="metric-card mc-purple">
                        <div class="mc-icon"><i class="fas fa-shirt"></i></div>
                        <div class="mc-value"><?= number_format($total_products) ?></div>
                        <div class="mc-label">Total Products</div>
                    </div>

                    <div class="metric-card mc-blue">
                        <div class="mc-icon"><i class="fas fa-tags"></i></div>
                        <div class="mc-value"><?= number_format($total_categories) ?></div>
                        <div class="mc-label">Total Categories</div>
                    </div>

                    <div class="metric-card mc-pink">
                        <div class="mc-icon"><i class="fas fa-medal"></i></div>
                        <div class="mc-value"><?= number_format($total_brands) ?></div>
                        <div class="mc-label">Total Brands</div>
                    </div>

                    <div class="metric-card mc-teal">
                        <div class="mc-icon"><i class="fas fa-truck"></i></div>
                        <div class="mc-value"><?= number_format($total_suppliers) ?></div>
                        <div class="mc-label">Total Suppliers</div>
                    </div>

                </div>

                <!-- ══ Section 2: Sales & Customers ══ -->
                <div class="metrics-section-title"><i class="fas fa-chart-line"></i> Sales & Customers</div>
                <div class="metrics-grid">

                    <div class="metric-card mc-cyan">
                        <div class="mc-icon"><i class="fas fa-users"></i></div>
                        <div class="mc-value"><?= number_format($total_customers) ?></div>
                        <div class="mc-label">Total Customers</div>
                    </div>

                    <div class="metric-card mc-indigo">
                        <div class="mc-icon"><i class="fas fa-bag-shopping"></i></div>
                        <div class="mc-value"><?= number_format($total_orders) ?></div>
                        <div class="mc-label">Total Orders</div>
                    </div>

                    <div class="metric-card mc-green">
                        <div class="mc-icon"><i class="fas fa-sack-dollar"></i></div>
                        <div class="mc-value small">LKR <?= number_format($total_revenue, 0) ?></div>
                        <div class="mc-label">Total Revenue</div>
                    </div>

                    <div class="metric-card mc-amber">
                        <div class="mc-icon"><i class="fas fa-star-half-stroke"></i></div>
                        <div class="mc-value"><?= number_format($total_feedbacks) ?></div>
                        <div class="mc-label">Total Feedbacks</div>
                    </div>

                </div>

                <!-- ══ Section 3: Alerts ══ -->
                <div class="metrics-section-title"><i class="fas fa-triangle-exclamation"></i> Alerts & Pending</div>
                <div class="metrics-grid">

                    <div class="metric-card mc-orange">
                        <?php if ($low_stock > 0): ?>
                            <span class="mc-badge mc-badge-warn">⚠ Alert</span>
                        <?php endif; ?>
                        <div class="mc-icon"><i class="fas fa-box-open"></i></div>
                        <div class="mc-value"><?= number_format($low_stock) ?></div>
                        <div class="mc-label">Low Stock Products</div>
                    </div>

                    <div class="metric-card mc-red">
                        <?php if ($out_of_stock > 0): ?>
                            <span class="mc-badge mc-badge-danger">✕ Out</span>
                        <?php endif; ?>
                        <div class="mc-icon"><i class="fas fa-box-xmark"></i></div>
                        <div class="mc-value"><?= number_format($out_of_stock) ?></div>
                        <div class="mc-label">Out of Stock</div>
                    </div>

                    <div class="metric-card mc-violet">
                        <?php if ($pending_orders > 0): ?>
                            <span class="mc-badge mc-badge-warn">Pending</span>
                        <?php endif; ?>
                        <div class="mc-icon"><i class="fas fa-clock-rotate-left"></i></div>
                        <div class="mc-value"><?= number_format($pending_orders) ?></div>
                        <div class="mc-label">Pending Orders</div>
                    </div>

                    <div class="metric-card mc-rose">
                        <?php if ($pending_payments > 0): ?>
                            <span class="mc-badge mc-badge-danger">Due</span>
                        <?php endif; ?>
                        <div class="mc-icon"><i class="fas fa-credit-card"></i></div>
                        <div class="mc-value"><?= number_format($pending_payments) ?></div>
                        <div class="mc-label">Pending Payments</div>
                    </div>

                </div>

                <!-- ══ Tables Row ══ -->
                <div class="tables-grid" style="margin-top:2rem;">

                    <!-- Recent Orders -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-bag-shopping" style="color:var(--primary-light);"></i>
                            <h2>Recent Orders</h2>
                        </div>
                        <?php if (!empty($recent_orders)): ?>
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th>#ID</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_orders as $ord): ?>
                                        <?php
                                        $sp_class = 'sp-' . strtolower($ord['order_status']);
                                        ?>
                                        <tr>
                                            <td style="color:var(--text-muted);">#<?= $ord['order_id'] ?></td>
                                            <td><?= htmlspecialchars($ord['full_name']) ?></td>
                                            <td>LKR <?= number_format($ord['total_amount'], 2) ?></td>
                                            <td><span class="status-pill <?= $sp_class ?>"><?= $ord['order_status'] ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:0.875rem; padding:1rem 0;">No orders yet.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Low Stock Alert Table -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="fas fa-triangle-exclamation" style="color:var(--accent);"></i>
                            <h2>Low Stock Alert</h2>
                        </div>
                        <?php if (!empty($low_stock_items)): ?>
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Stock</th>
                                        <th>Reorder</th>
                                        <th>Bar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($low_stock_items as $item): ?>
                                        <?php
                                        $pct   = $item['reorder_level'] > 0 ? min(100, ($item['stock_qty'] / $item['reorder_level']) * 100) : 0;
                                        $color = $item['stock_qty'] == 0 ? '#ef4444' : ($pct < 50 ? '#f59e0b' : '#10b981');
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                                            <td style="color:<?= $color ?>; font-weight:700;"><?= $item['stock_qty'] ?></td>
                                            <td style="color:var(--text-muted);"><?= $item['reorder_level'] ?></td>
                                            <td>
                                                <div class="stock-bar-wrap">
                                                    <div class="stock-bar" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:0.875rem; padding:1rem 0;">
                                <i class="fas fa-circle-check" style="color:var(--success);"></i> All products are well stocked!
                            </p>
                        <?php endif; ?>
                    </div>

                </div><!-- /tables-grid -->

            </div><!-- /dashboard-main -->

        </div><!-- /dashboard-container -->
    </div><!-- /dashboard-wrapper -->

    <script src="assets/js/owner.js"></script>
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