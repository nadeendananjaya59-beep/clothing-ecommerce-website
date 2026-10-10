<?php

/**
 * Customer Management Module - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// Check if we are viewing a single customer profile
$view_customer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($view_customer_id > 0) {
    // ────────────────────────────────────────────────────────
    // SINGLE CUSTOMER VIEW MODE
    // ────────────────────────────────────────────────────────

    // Fetch customer profile
    $cust_sql = "SELECT c.* FROM customers c 
                 JOIN orders o ON c.customer_id = o.customer_id 
                 WHERE c.customer_id = $view_customer_id AND o.shop_id = $shop_id 
                 LIMIT 1";
    $cust_res = mysqli_query($conn, $cust_sql);
    $customer = mysqli_fetch_assoc($cust_res);

    if (!$customer) {
        set_flash('danger', 'Customer profile not found or does not belong to this shop.');
        header('Location: customers.php');
        exit();
    }

    // Spending Summary Metrics for this customer in this shop
    $summary_sql = "SELECT 
                        COUNT(order_id) AS total_orders,
                        COALESCE(SUM(total_amount), 0) AS total_spent,
                        COALESCE(AVG(total_amount), 0) AS avg_value,
                        MAX(order_date) AS last_order_date
                    FROM orders 
                    WHERE customer_id = $view_customer_id AND shop_id = $shop_id AND order_status != 'Cancelled'";
    $summary_res = mysqli_query($conn, $summary_sql);
    $spending_summary = mysqli_fetch_assoc($summary_res);

    // Purchase History (all orders of this customer in this shop)
    $orders_sql = "SELECT o.order_id, o.order_date, o.total_amount, o.order_status,
                          p.payment_status, pm.method_name
                   FROM orders o
                   LEFT JOIN payments p ON p.order_id = o.order_id
                   LEFT JOIN payment_methods pm ON p.method_id = pm.method_id
                   WHERE o.customer_id = $view_customer_id AND o.shop_id = $shop_id
                   ORDER BY o.order_date DESC";
    $orders_res = mysqli_query($conn, $orders_sql);
    $cust_orders = [];
    if ($orders_res) {
        while ($row = mysqli_fetch_assoc($orders_res)) {
            $cust_orders[] = $row;
        }
    }
} else {
    // ────────────────────────────────────────────────────────
    // LIST VIEW MODE
    // ────────────────────────────────────────────────────────

    // 1. Total Customers count for this shop (distinct customers who ordered)
    $r = mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) AS total FROM orders WHERE shop_id = $shop_id");
    $total_customers = mysqli_fetch_assoc($r)['total'];

    // 2. New Customers This Month (first order placed in this shop this month)
    $month_start = date('Y-m-01');
    $new_cust_sql = "SELECT COUNT(DISTINCT customer_id) AS new_count 
                     FROM orders 
                     WHERE shop_id = $shop_id 
                     AND customer_id NOT IN (
                         SELECT DISTINCT customer_id FROM orders WHERE shop_id = $shop_id AND order_date < '$month_start'
                     )";
    $r = mysqli_query($conn, $new_cust_sql);
    $new_customers = mysqli_fetch_assoc($r)['new_count'];

    // 3. Top Spender name & amount
    $top_sql = "SELECT c.full_name, SUM(o.total_amount) AS total_spent 
                FROM orders o 
                JOIN customers c ON o.customer_id = c.customer_id 
                WHERE o.shop_id = $shop_id AND o.order_status != 'Cancelled'
                GROUP BY o.customer_id 
                ORDER BY total_spent DESC 
                LIMIT 1";
    $top_res = mysqli_query($conn, $top_sql);
    $top_spender = mysqli_fetch_assoc($top_res);
    $top_spender_name = $top_spender ? $top_spender['full_name'] : '—';
    $top_spender_amount = $top_spender ? $top_spender['total_spent'] : 0;

    // Filters for list
    $f_search = trim($_GET['search'] ?? '');

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 15;
    $offset   = ($page - 1) * $per_page;

    // Build query to list distinct customers with summary metrics
    $where = ["o.shop_id = $shop_id"];
    if ($f_search !== '') {
        $search_esc = mysqli_real_escape_string($conn, $f_search);
        $where[] = "(c.full_name LIKE '%$search_esc%' OR c.email LIKE '%$search_esc%' OR c.phone LIKE '%$search_esc%')";
    }
    $where_sql = implode(' AND ', $where);

    // Count distinct customers match filters
    $count_sql = "SELECT COUNT(DISTINCT o.customer_id) AS c 
                  FROM orders o 
                  JOIN customers c ON o.customer_id = c.customer_id 
                  WHERE $where_sql";
    $count_res = mysqli_query($conn, $count_sql);
    $total_filtered = mysqli_fetch_assoc($count_res)['c'];
    $total_pages = max(1, ceil($total_filtered / $per_page));

    // Main customers summary list
    $list_sql = "SELECT c.customer_id, c.full_name, c.email, c.phone, c.address, c.created_at,
                        COUNT(o.order_id) AS orders_count,
                        SUM(o.total_amount) AS total_spending,
                        MAX(o.order_date) AS last_order_date
                 FROM customers c
                 JOIN orders o ON c.customer_id = o.customer_id
                 WHERE $where_sql
                 GROUP BY c.customer_id
                 ORDER BY total_spending DESC
                 LIMIT $per_page OFFSET $offset";
    $list_res = mysqli_query($conn, $list_sql);
    $customer_list = [];
    if ($list_res) {
        while ($row = mysqli_fetch_assoc($list_res)) {
            $customer_list[] = $row;
        }
    }

    // Top 5 Customers list for display cards
    $top_5_sql = "SELECT c.customer_id, c.full_name, c.email, COUNT(o.order_id) AS orders_count, SUM(o.total_amount) AS total_spending
                  FROM orders o
                  JOIN customers c ON o.customer_id = c.customer_id
                  WHERE o.shop_id = $shop_id AND o.order_status != 'Cancelled'
                  GROUP BY o.customer_id
                  ORDER BY total_spending DESC
                  LIMIT 5";
    $top_5_res = mysqli_query($conn, $top_5_sql);
    $top_5_customers = [];
    if ($top_5_res) {
        while ($row = mysqli_fetch_assoc($top_5_res)) {
            $top_5_customers[] = $row;
        }
    }
    $max_spending = !empty($top_5_customers) ? max(1, $top_5_customers[0]['total_spending']) : 1;
}

// Helper status classes for order history
function status_class($s)
{
    return match ($s) {
        'Pending'    => 'sp-pending',
        'Processing' => 'sp-processing',
        'Shipped'    => 'sp-shipped',
        'Delivered'  => 'sp-delivered',
        'Cancelled'  => 'sp-cancelled',
        default      => ''
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        /* ── Grid stats ── */
        .cust-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media(max-width: 768px) {
            .cust-grid {
                grid-template-columns: 1fr;
            }
        }

        .cust-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: all var(--transition);
        }

        .cust-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }

        .cust-card::before {
            content: '';
            position: absolute;
            top: -25px;
            right: -15px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            opacity: .08;
        }

        .cc-purple {
            border-top: 3px solid #6c63ff;
        }

        .cc-purple::before {
            background: #6c63ff;
        }

        .cc-purple .cc-icon {
            background: rgba(108, 99, 255, .15);
            color: #8b84ff;
        }

        .cc-blue {
            border-top: 3px solid #3b82f6;
        }

        .cc-blue::before {
            background: #3b82f6;
        }

        .cc-blue .cc-icon {
            background: rgba(59, 130, 246, .15);
            color: #60a5fa;
        }

        .cc-green {
            border-top: 3px solid #10b981;
        }

        .cc-green::before {
            background: #10b981;
        }

        .cc-green .cc-icon {
            background: rgba(16, 185, 129, .15);
            color: #34d399;
        }

        .cc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }

        .cc-sub {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: .2rem;
        }

        .cc-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }

        .cc-val.sm {
            font-size: 1.25rem;
        }

        /* Search Filter style */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: .4rem;
            flex: 1;
        }

        .filter-group label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
        }

        .filter-group input {
            background: var(--bg-input);
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: .875rem;
            padding: .65rem .9rem;
            outline: none;
            font-family: 'Inter', sans-serif;
            transition: border-color var(--transition);
            width: 100%;
        }

        .filter-group input:focus {
            border-color: var(--primary);
        }

        .btn-filter {
            padding: .7rem 1.4rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: .4rem;
            font-family: 'Inter', sans-serif;
            transition: all var(--transition);
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 99, 255, .4);
        }

        .btn-reset {
            padding: .7rem 1.1rem;
            background: transparent;
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: .875rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all var(--transition);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .btn-reset:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Tables & Lists */
        .card-box {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-box-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }

        .card-box-header h2 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
        }

        .cust-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cust-table th {
            text-align: left;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            padding: .8rem 1rem;
            border-bottom: 1px solid var(--border-light);
            background: rgba(255, 255, 255, .01);
        }

        .cust-table td {
            padding: .9rem 1rem;
            font-size: .86rem;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(255, 255, 255, .04);
            vertical-align: middle;
        }

        .cust-table tr:last-child td {
            border-bottom: none;
        }

        .cust-table tbody tr:hover td {
            background: rgba(108, 99, 255, .04);
        }

        /* Status pills */
        .status-pill {
            display: inline-block;
            padding: .2rem .65rem;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .sp-pending {
            background: rgba(245, 158, 11, .12);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, .25);
        }

        .sp-processing {
            background: rgba(59, 130, 246, .12);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, .25);
        }

        .sp-shipped {
            background: rgba(139, 92, 246, .12);
            color: #a78bfa;
            border: 1px solid rgba(139, 92, 246, .25);
        }

        .sp-delivered {
            background: rgba(16, 185, 129, .12);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .sp-cancelled {
            background: rgba(239, 68, 68, .12);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, .25);
        }

        /* Profile details layout */
        .profile-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 1.5rem;
        }

        @media(max-width: 900px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
        }

        .profile-side {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .avatar-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            font-size: 2.2rem;
            font-weight: 800;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 8px 24px rgba(108, 99, 255, 0.3);
        }

        .top-cust-progress {
            height: 6px;
            background: var(--border-light);
            border-radius: 3px;
            overflow: hidden;
            margin-top: .4rem;
        }

        .top-cust-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 3px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: 1.2rem 1.5rem;
            border-top: 1px solid var(--border-light);
        }

        .pg-btn {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .82rem;
            font-weight: 600;
            border: 1px solid var(--border-light);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            text-decoration: none;
            transition: all var(--transition);
        }

        .pg-btn:hover,
        .pg-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .pg-btn.disabled {
            opacity: .35;
            pointer-events: none;
        }

        .pg-info {
            margin-left: auto;
            font-size: .8rem;
            color: var(--text-muted);
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
        <div class="dashboard-container">
            <?php require_once __DIR__ . '/includes/sidebar_quick_actions.php'; ?>

            <div class="dashboard-main">

                <?php if ($view_customer_id > 0 && $customer): ?>

                    <!-- ────────────────────────────────────────────────────────
             VIEW MODE: CUSTOMER PROFILE & PURCHASE HISTORY
             ──────────────────────────────────────────────────────── -->

                    <!-- Header -->
                    <div class="dashboard-header">
                        <h1>👤 Customer Profile Details</h1>
                        <p>Review customer profile details, aggregate metrics, and order history.</p>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <a href="customers.php" class="btn-reset" style="display:inline-flex;"><i class="fas fa-chevron-left"></i> Back to Customer List</a>
                    </div>

                    <div class="profile-layout">

                        <!-- Sidebar: Details Card -->
                        <div class="profile-side">
                            <div class="cust-card" style="text-align:center; padding:2rem 1.5rem;">
                                <div class="avatar-circle"><?= strtoupper(substr($customer['full_name'], 0, 1)) ?></div>
                                <h3 style="margin-bottom:.3rem; font-size:1.15rem;"><?= htmlspecialchars($customer['full_name']) ?></h3>
                                <span class="badge badge-instock" style="font-size:0.65rem;">Active Buyer</span>

                                <table class="info-table" style="margin-top:1.5rem; text-align:left;">
                                    <tr>
                                        <td class="info-key" style="font-size:0.7rem;">Email</td>
                                        <td class="info-val" style="word-break:break-all; font-size:0.8rem;"><?= htmlspecialchars($customer['email']) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-key" style="font-size:0.7rem;">Phone</td>
                                        <td class="info-val" style="font-size:0.8rem;"><?= htmlspecialchars($customer['phone'] ?? '—') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-key" style="font-size:0.7rem;">Address</td>
                                        <td class="info-val" style="font-size:0.8rem;"><?= htmlspecialchars($customer['address'] ?? '—') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-key" style="font-size:0.7rem;">Created</td>
                                        <td class="info-val" style="font-size:0.8rem;"><?= date('d M Y', strtotime($customer['created_at'])) ?></td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Spending Summary Card -->
                            <div class="cust-card">
                                <div style="display:flex; align-items:center; gap:.5rem; margin-bottom:1rem; border-bottom:1px solid var(--border-light); padding-bottom:.5rem;">
                                    <i class="fas fa-receipt" style="color:var(--primary-light);"></i>
                                    <h4 style="margin:0; font-size:0.85rem; text-transform:uppercase; color:var(--text-secondary);">Spending Summary</h4>
                                </div>
                                <table class="info-table">
                                    <tr>
                                        <td class="info-key">Total Orders</td>
                                        <td class="info-val" style="font-weight:700; color:var(--primary-light);"><?= number_format($spending_summary['total_orders']) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-key">Total Spent</td>
                                        <td class="info-val" style="font-weight:700; color:#34d399;">LKR <?= number_format($spending_summary['total_spent'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-key">Avg Order Val</td>
                                        <td class="info-val">LKR <?= number_format($spending_summary['avg_value'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="info-key">Last Order</td>
                                        <td class="info-val" style="font-size:0.75rem; color:var(--text-secondary);"><?= $spending_summary['last_order_date'] ? date('d M Y', strtotime($spending_summary['last_order_date'])) : '—' ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Content Area: Order History -->
                        <div class="card-box" style="margin:0;">
                            <div class="card-box-header">
                                <h2>Customer Purchase History in Your Shop</h2>
                                <span class="count-badge" style="background:rgba(108,99,255,.15); color:var(--primary-light); padding:.2rem .65rem; border-radius:20px; font-size:.75rem; font-weight:700; border:1px solid rgba(108,99,255,.25);"><?= count($cust_orders) ?> orders</span>
                            </div>

                            <?php if (!empty($cust_orders)): ?>
                                <div style="overflow-x:auto;">
                                    <table class="cust-table">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Order Date</th>
                                                <th style="text-align:right;">Amount</th>
                                                <th style="text-align:center;">Payment</th>
                                                <th>Method</th>
                                                <th style="text-align:center;">Order Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cust_orders as $ord): ?>
                                                <tr>
                                                    <td style="color:var(--primary-light); font-weight:700;">#<?= $ord['order_id'] ?></td>
                                                    <td style="font-size:0.8rem; color:var(--text-secondary);"><?= date('d M Y, h:i A', strtotime($ord['order_date'])) ?></td>
                                                    <td style="text-align:right; font-weight:700;">LKR <?= number_format($ord['total_amount'], 2) ?></td>
                                                    <td style="text-align:center;">
                                                        <?php if ($ord['payment_status']): ?>
                                                            <span class="status-pill sp-<?= strtolower($ord['payment_status'] === 'Completed' ? 'delivered' : ($ord['payment_status'] === 'Pending' ? 'pending' : 'cancelled')) ?>">
                                                                <?= $ord['payment_status'] ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span style="color:var(--text-muted);">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($ord['method_name'] ?? '—') ?></td>
                                                    <td style="text-align:center;">
                                                        <span class="status-pill <?= status_class($ord['order_status']) ?>"><?= $ord['order_status'] ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div style="padding:4rem 2rem; text-align:center;">
                                    <i class="fas fa-bag-shopping" style="font-size:3rem; color:var(--text-muted); margin-bottom:1rem; display:block;"></i>
                                    <p style="color:var(--text-muted); margin:0;">This customer hasn't placed any orders in your shop yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                <?php else: ?>

                    <!-- ────────────────────────────────────────────────────────
             LIST MODE: ALL CUSTOMERS OVERVIEW
             ──────────────────────────────────────────────────────── -->

                    <!-- Header -->
                    <div class="dashboard-header">
                        <h1>👥 Customer Management</h1>
                        <p>Manage customer relationships, view buyer statistics, and track order lists.</p>
                    </div>

                    <!-- ══ Stats Grid ══ -->
                    <div class="cust-grid">
                        <div class="cust-card cc-purple">
                            <div class="cc-icon"><i class="fas fa-user-group"></i></div>
                            <div class="cc-sub">Total Customers</div>
                            <div class="cc-val"><?= number_format($total_customers) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">buyers</span></div>
                        </div>
                        <div class="cust-card cc-blue">
                            <div class="cc-icon"><i class="fas fa-user-plus"></i></div>
                            <div class="cc-sub">New This Month</div>
                            <div class="cc-val"><?= number_format($new_customers) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">buyers</span></div>
                        </div>
                        <div class="cust-card cc-green">
                            <div class="cc-icon"><i class="fas fa-crown"></i></div>
                            <div class="cc-sub">Top Spender</div>
                            <div class="cc-val sm" title="<?= htmlspecialchars($top_spender_name) ?>"><?= htmlspecialchars(strlen($top_spender_name) > 18 ? substr($top_spender_name, 0, 15) . '...' : $top_spender_name) ?> <span style="font-size:0.8rem; font-weight:500; color:#34d399; block:inline; margin-left:3px;">LKR <?= number_format($top_spender_amount) ?></span></div>
                        </div>
                    </div>

                    <!-- ══ Top Spenders Progress Display ══ -->
                    <div class="card-box" style="padding:1.5rem; margin-bottom: 2rem;">
                        <div style="font-size:.85rem; font-weight:700; text-transform:uppercase; color:var(--text-secondary); margin-bottom:1.2rem;"><i class="fas fa-ranking-star" style="color:var(--accent); margin-right:0.4rem;"></i> Top Spenders Breakdown</div>
                        <?php if (!empty($top_5_customers)): ?>
                            <?php foreach ($top_5_customers as $tc):
                                $pct = round(($tc['total_spending'] / $max_spending) * 100);
                            ?>
                                <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem;">
                                    <div style="width:160px; font-size:.85rem; font-weight:600;"><a href="customers.php?id=<?= $tc['customer_id'] ?>" style="color:var(--text-primary); text-decoration:none;"><?= htmlspecialchars($tc['full_name']) ?></a></div>
                                    <div style="flex:1;" class="top-cust-progress">
                                        <div class="top-cust-fill" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                    <div style="width:180px; text-align:right; font-weight:700; font-size:.85rem; color:var(--text-primary);">
                                        LKR <?= number_format($tc['total_spending'], 2) ?> &nbsp;<span style="font-weight:500; color:var(--text-muted); font-size:0.75rem;">(<?= $tc['orders_count'] ?> orders)</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:0.85rem; margin:0;">No buyer history found.</p>
                        <?php endif; ?>
                    </div>

                    <!-- ══ Filter Search Card ══ -->
                    <div class="filter-card">
                        <form method="GET" action="customers.php">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label><i class="fas fa-magnifying-glass"></i> Search Customer Directory</label>
                                    <input type="text" name="search" placeholder="Search by name, email, or telephone number..." value="<?= htmlspecialchars($f_search) ?>">
                                </div>
                                <div style="display:flex; gap:.5rem;">
                                    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply Filters</button>
                                    <a href="customers.php" class="btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ══ Customer Main Listing Table ══ -->
                    <div class="card-box">
                        <div class="card-box-header">
                            <h2>Active Customer Directory</h2>
                            <span class="count-badge" style="background:rgba(108,99,255,.15); color:var(--primary-light); padding:.2rem .65rem; border-radius:20px; font-size:.75rem; font-weight:700; border:1px solid rgba(108,99,255,.25);"><?= number_format($total_filtered) ?> matching</span>
                        </div>

                        <?php if (!empty($customer_list)): ?>
                            <div style="overflow-x:auto;">
                                <table class="cust-table">
                                    <thead>
                                        <tr>
                                            <th>Buyer Details</th>
                                            <th>Contact Info</th>
                                            <th>Address Location</th>
                                            <th style="text-align:center;">Orders Placed</th>
                                            <th style="text-align:right;">Total Spent</th>
                                            <th>Last Activity</th>
                                            <th style="text-align:center;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customer_list as $c): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($c['full_name']) ?></strong><br>
                                                    <span style="font-size:0.75rem; color:var(--text-muted);">ID: #<?= $c['customer_id'] ?></span>
                                                </td>
                                                <td>
                                                    <i class="fas fa-envelope" style="font-size:0.75rem; color:var(--text-muted); margin-right:3px;"></i> <?= htmlspecialchars($c['email']) ?><br>
                                                    <i class="fas fa-phone" style="font-size:0.75rem; color:var(--text-muted); margin-right:3px;"></i> <?= htmlspecialchars($c['phone'] ?? '—') ?>
                                                </td>
                                                <td style="font-size:0.8rem; color:var(--text-secondary); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <?= htmlspecialchars($c['address'] ?? '—') ?>
                                                </td>
                                                <td style="text-align:center; font-weight:600; color:var(--primary-light);"><?= $c['orders_count'] ?></td>
                                                <td style="text-align:right; font-weight:700; color:#34d399;">LKR <?= number_format($c['total_spending'], 2) ?></td>
                                                <td style="color:var(--text-muted); font-size:0.8rem;">
                                                    <?= $c['last_order_date'] ? date('d M Y', strtotime($c['last_order_date'])) : '—' ?>
                                                </td>
                                                <td style="text-align:center;">
                                                    <a href="customers.php?id=<?= $c['customer_id'] ?>" class="btn btn-outline" style="padding:0.4rem 0.8rem; font-size:0.75rem; display:inline-flex;">
                                                        <i class="fas fa-user-circle"></i> View Profile & History
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <?php
                                $params = array_filter(['search' => $f_search]);
                                $query_string = http_build_query($params);
                                $base_url = 'customers.php?' . ($query_string ? $query_string . '&' : '');
                                ?>
                                <div class="pagination">
                                    <a class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                        <a class="pg-btn <?= $p === $page ? 'active' : '' ?>" href="<?= $base_url ?>page=<?= $p ?>"><?= $p ?></a>
                                    <?php endfor; ?>
                                    <a class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                                    <span class="pg-info">Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_filtered) ?> customers</span>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div style="text-align:center; padding:3rem 2rem;">
                                <i class="fas fa-users-slash" style="font-size:3rem; color:var(--text-muted); margin-bottom:1rem; display:block;"></i>
                                <p style="color:var(--text-muted); margin:0;">No customer records found match filters.</p>
                                <a href="customers.php" class="btn-reset" style="display:inline-flex; margin-top:1rem;"><i class="fas fa-rotate-left"></i> Clear Filters</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Back Link -->
                    <div style="margin-top: 1.5rem;">
                        <a href="dashboard.php" class="btn-reset" style="display:inline-flex;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    </div>

                <?php endif; ?>

            </div>
        </div>

</body>

</html>