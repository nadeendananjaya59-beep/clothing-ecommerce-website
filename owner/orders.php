<?php

/**
 * Sales & Orders Management - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// ─── Summary Stats ────────────────────────────────────────
$today = date('Y-m-d');
$month_start = date('Y-m-01');

// Today's orders
$r = mysqli_query($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS rev FROM orders WHERE DATE(order_date)='$today'");
$td = mysqli_fetch_assoc($r);
$today_orders  = $td['c'];
$today_revenue = $td['rev'];

// This month's orders
$r = mysqli_query($conn, "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS rev FROM orders WHERE order_date >= '$month_start'");
$mo = mysqli_fetch_assoc($r);
$month_orders  = $mo['c'];
$month_revenue = $mo['rev'];

// Status counts
$status_counts = [];
$statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
foreach ($statuses as $st) {
    $esc = mysqli_real_escape_string($conn, $st);
    $r   = mysqli_query($conn, "SELECT COUNT(*) AS c FROM orders WHERE order_status='$esc'");
    $row = mysqli_fetch_assoc($r);
    $status_counts[$st] = $row['c'];
}

// ─── Filters ─────────────────────────────────────────────
$f_search    = trim($_GET['search']     ?? '');
$f_status    = trim($_GET['status']     ?? '');
$f_customer  = trim($_GET['customer']   ?? '');
$f_date_from = trim($_GET['date_from']  ?? '');
$f_date_to   = trim($_GET['date_to']    ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 15;
$offset      = ($page - 1) * $per_page;

// Build WHERE
$where = ['1=1'];
$params = [];
$types = '';

if ($f_search !== '') {
    $like = '%' . mysqli_real_escape_string($conn, $f_search) . '%';
    $where[] = "(c.full_name LIKE '$like' OR o.order_id LIKE '$like' OR c.email LIKE '$like')";
}
if ($f_status !== '' && in_array($f_status, $statuses)) {
    $esc = mysqli_real_escape_string($conn, $f_status);
    $where[] = "o.order_status = '$esc'";
}
if ($f_customer !== '') {
    $like = '%' . mysqli_real_escape_string($conn, $f_customer) . '%';
    $where[] = "c.full_name LIKE '$like'";
}
if ($f_date_from !== '') {
    $esc = mysqli_real_escape_string($conn, $f_date_from);
    $where[] = "DATE(o.order_date) >= '$esc'";
}
if ($f_date_to !== '') {
    $esc = mysqli_real_escape_string($conn, $f_date_to);
    $where[] = "DATE(o.order_date) <= '$esc'";
}

$where_sql = implode(' AND ', $where);

// Count total rows
$count_sql = "SELECT COUNT(*) AS c FROM orders o JOIN customers c ON o.customer_id=c.customer_id WHERE $where_sql";
$count_res = mysqli_query($conn, $count_sql);
$total_rows = mysqli_fetch_assoc($count_res)['c'];
$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch orders
$orders_sql = "SELECT o.order_id, o.order_date, o.total_amount, o.order_status,
                      c.full_name AS customer_name, c.email AS customer_email,
                      c.phone AS customer_phone,
                      p.payment_status, pm.method_name,
                      COUNT(oi.order_item_id) AS item_count
               FROM orders o
               JOIN customers c ON o.customer_id = c.customer_id
               LEFT JOIN payments p ON p.order_id = o.order_id
               LEFT JOIN payment_methods pm ON pm.method_id = p.method_id
               LEFT JOIN order_items oi ON oi.order_id = o.order_id
               WHERE $where_sql
               GROUP BY o.order_id
               ORDER BY o.order_date DESC
               LIMIT $per_page OFFSET $offset";
$orders_res = mysqli_query($conn, $orders_sql);
$orders = [];
if ($orders_res) {
    while ($row = mysqli_fetch_assoc($orders_res)) {
        $orders[] = $row;
    }
}

// ─── Status colours ───────────────────────────────────────
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
function pay_class($s)
{
    return match ($s) {
        'Completed' => 'sp-delivered',
        'Pending'   => 'sp-pending',
        'Failed'    => 'sp-cancelled',
        'Refunded'  => 'sp-processing',
        default     => ''
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales & Orders | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        /* ── Page Extras ── */
        .page-header {
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 1.7rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }

        /* Summary cards */
        .sum-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.2rem;
            margin-bottom: 2rem;
        }

        @media(max-width:900px) {
            .sum-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width:480px) {
            .sum-grid {
                grid-template-columns: 1fr;
            }
        }

        .sum-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.3rem 1.2rem;
            position: relative;
            overflow: hidden;
            transition: all var(--transition);
        }

        .sum-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, .35);
        }

        .sum-card::before {
            content: '';
            position: absolute;
            top: -25px;
            right: -15px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            opacity: .08;
        }

        .sc-purple {
            border-top: 3px solid #6c63ff;
        }

        .sc-purple::before {
            background: #6c63ff;
        }

        .sc-purple .sc-icon {
            background: rgba(108, 99, 255, .15);
            color: #8b84ff;
        }

        .sc-green {
            border-top: 3px solid #10b981;
        }

        .sc-green::before {
            background: #10b981;
        }

        .sc-green .sc-icon {
            background: rgba(16, 185, 129, .15);
            color: #34d399;
        }

        .sc-amber {
            border-top: 3px solid #f59e0b;
        }

        .sc-amber::before {
            background: #f59e0b;
        }

        .sc-amber .sc-icon {
            background: rgba(245, 158, 11, .15);
            color: #fbbf24;
        }

        .sc-blue {
            border-top: 3px solid #3b82f6;
        }

        .sc-blue::before {
            background: #3b82f6;
        }

        .sc-blue .sc-icon {
            background: rgba(59, 130, 246, .15);
            color: #60a5fa;
        }

        .sc-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: .8rem;
        }

        .sc-sub {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: .2rem;
        }

        .sc-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }

        .sc-val.sm {
            font-size: 1.2rem;
        }

        /* Status summary bar */
        .status-bar {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-bottom: 2rem;
        }

        .st-chip {
            display: flex;
            align-items: center;
            gap: .5rem;
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 50px;
            padding: .45rem 1rem;
            font-size: .8rem;
            transition: all var(--transition);
        }

        .st-chip:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, .25);
        }

        .st-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .st-chip .count {
            font-weight: 800;
            color: var(--text-primary);
        }

        .st-chip .label {
            color: var(--text-muted);
        }

        /* Filters */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.4rem 1.6rem;
            margin-bottom: 1.5rem;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: .35rem;
            flex: 1;
            min-width: 160px;
        }

        .filter-group label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
        }

        .filter-group input,
        .filter-group select {
            background: var(--bg-input);
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: .875rem;
            padding: .6rem .9rem;
            outline: none;
            font-family: 'Inter', sans-serif;
            transition: border-color var(--transition);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: var(--primary);
        }

        .filter-group select option {
            background: #1a1a2e;
        }

        .btn-filter {
            padding: .65rem 1.4rem;
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
            white-space: nowrap;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 99, 255, .4);
        }

        .btn-reset {
            padding: .65rem 1.1rem;
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

        /* Orders table */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .table-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }

        .table-head h2 {
            font-size: 1rem;
            font-weight: 700;
        }

        .table-head .count-badge {
            background: rgba(108, 99, 255, .15);
            color: var(--primary-light);
            border: 1px solid rgba(108, 99, 255, .25);
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 700;
            padding: .2rem .65rem;
        }

        .orders-table {
            width: 100%;
            border-collapse: collapse;
        }

        .orders-table th {
            text-align: left;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            padding: .8rem 1rem;
            border-bottom: 1px solid var(--border-light);
            white-space: nowrap;
            background: rgba(255, 255, 255, .02);
        }

        .orders-table td {
            padding: .85rem 1rem;
            font-size: .86rem;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(255, 255, 255, .04);
            vertical-align: middle;
        }

        .orders-table tr:last-child td {
            border-bottom: none;
        }

        .orders-table tbody tr:hover td {
            background: rgba(108, 99, 255, .04);
        }

        .orders-table .order-id {
            color: var(--primary-light);
            font-weight: 700;
            font-size: .8rem;
        }

        .orders-table .cust-name {
            font-weight: 600;
        }

        .orders-table .cust-email {
            font-size: .75rem;
            color: var(--text-muted);
        }

        .orders-table .amount {
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Status pills */
        .status-pill {
            display: inline-block;
            padding: .22rem .7rem;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .3px;
            white-space: nowrap;
        }

        .sp-pending {
            background: rgba(245, 158, 11, .15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, .25);
        }

        .sp-processing {
            background: rgba(59, 130, 246, .15);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, .25);
        }

        .sp-shipped {
            background: rgba(139, 92, 246, .15);
            color: #a78bfa;
            border: 1px solid rgba(139, 92, 246, .25);
        }

        .sp-delivered {
            background: rgba(16, 185, 129, .15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .sp-cancelled {
            background: rgba(239, 68, 68, .15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, .25);
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

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3.5rem 2rem;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: .9rem;
        }

        /* Scrollable on mobile */
        .table-scroll {
            overflow-x: auto;
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

                <!-- Page Header -->
                <div class="page-header">
                    <h1>🛒 Sales & Orders</h1>
                    <p>Track, filter and manage all your shop orders in one place.</p>
                </div>

                <!-- ══ Summary Cards ══ -->
                <div class="sum-grid">
                    <div class="sum-card sc-purple">
                        <div class="sc-icon"><i class="fas fa-bag-shopping"></i></div>
                        <div class="sc-sub">Today's Orders</div>
                        <div class="sc-val"><?= number_format($today_orders) ?></div>
                    </div>
                    <div class="sum-card sc-blue">
                        <div class="sc-icon"><i class="fas fa-calendar-days"></i></div>
                        <div class="sc-sub">This Month Orders</div>
                        <div class="sc-val"><?= number_format($month_orders) ?></div>
                    </div>
                    <div class="sum-card sc-green">
                        <div class="sc-icon"><i class="fas fa-coins"></i></div>
                        <div class="sc-sub">Today's Revenue</div>
                        <div class="sc-val sm">LKR <?= number_format($today_revenue, 2) ?></div>
                    </div>
                    <div class="sum-card sc-amber">
                        <div class="sc-icon"><i class="fas fa-sack-dollar"></i></div>
                        <div class="sc-sub">Month Revenue</div>
                        <div class="sc-val sm">LKR <?= number_format($month_revenue, 2) ?></div>
                    </div>
                </div>

                <!-- ══ Order Status Summary Bar ══ -->
                <div class="status-bar">
                    <?php
                    $st_colors = [
                        'Pending'    => ['dot' => '#f59e0b', 'bg' => 'rgba(245,158,11,.12)'],
                        'Processing' => ['dot' => '#3b82f6', 'bg' => 'rgba(59,130,246,.12)'],
                        'Shipped'    => ['dot' => '#8b5cf6', 'bg' => 'rgba(139,92,246,.12)'],
                        'Delivered'  => ['dot' => '#10b981', 'bg' => 'rgba(16,185,129,.12)'],
                        'Cancelled'  => ['dot' => '#ef4444', 'bg' => 'rgba(239,68,68,.12)'],
                    ];
                    foreach ($statuses as $st):
                        $col = $st_colors[$st];
                        $url = '?' . http_build_query(array_merge($_GET, ['status' => $st, 'page' => 1]));
                    ?>
                        <a href="<?= htmlspecialchars($url) ?>" class="st-chip" style="text-decoration:none;background:<?= $col['bg'] ?>;">
                            <span class="st-dot" style="background:<?= $col['dot'] ?>;"></span>
                            <span class="count"><?= $status_counts[$st] ?></span>
                            <span class="label"><?= $st ?></span>
                        </a>
                    <?php endforeach; ?>
                    <a href="orders.php" class="st-chip" style="text-decoration:none;">
                        <i class="fas fa-list" style="color:var(--text-muted);font-size:.75rem;"></i>
                        <span class="label">All Orders</span>
                    </a>
                </div>

                <!-- ══ Filter Bar ══ -->
                <div class="filter-card">
                    <form method="GET" action="orders.php">
                        <div class="filter-row">

                            <div class="filter-group" style="flex:2; min-width:200px;">
                                <label><i class="fas fa-magnifying-glass"></i> Search</label>
                                <input type="text" name="search" placeholder="Order ID, customer name, email…"
                                    value="<?= htmlspecialchars($f_search) ?>">
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-user"></i> Customer</label>
                                <input type="text" name="customer" placeholder="Customer name…"
                                    value="<?= htmlspecialchars($f_customer) ?>">
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-circle-half-stroke"></i> Status</label>
                                <select name="status">
                                    <option value="">All Statuses</option>
                                    <?php foreach ($statuses as $st): ?>
                                        <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-calendar-day"></i> Date From</label>
                                <input type="date" name="date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-calendar-check"></i> Date To</label>
                                <input type="date" name="date_to" value="<?= htmlspecialchars($f_date_to) ?>">
                            </div>

                            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                                <button type="submit" class="btn-filter">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="orders.php" class="btn-reset">
                                    <i class="fas fa-rotate-left"></i> Reset
                                </a>
                            </div>

                        </div>
                    </form>
                </div>

                <!-- ══ Orders Table ══ -->
                <div class="table-card">
                    <div class="table-head">
                        <h2><i class="fas fa-list-check" style="color:var(--primary-light);margin-right:.4rem;"></i> Order Records</h2>
                        <span class="count-badge"><?= number_format($total_rows) ?> result<?= $total_rows != 1 ? 's' : '' ?></span>
                    </div>

                    <?php if (!empty($orders)): ?>
                        <div class="table-scroll">
                            <table class="orders-table">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Customer</th>
                                        <th>Items</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th>Method</th>
                                        <th>Order Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $o): ?>
                                        <tr>
                                            <td><span class="order-id">#<?= $o['order_id'] ?></span></td>
                                            <td>
                                                <div class="cust-name"><?= htmlspecialchars($o['customer_name']) ?></div>
                                                <div class="cust-email"><?= htmlspecialchars($o['customer_email']) ?></div>
                                            </td>
                                            <td style="text-align:center;">
                                                <span style="background:rgba(108,99,255,.1);color:var(--primary-light);border-radius:20px;padding:.15rem .6rem;font-size:.75rem;font-weight:700;">
                                                    <?= $o['item_count'] ?>
                                                </span>
                                            </td>
                                            <td class="amount">LKR <?= number_format($o['total_amount'], 2) ?></td>
                                            <td>
                                                <?php if ($o['payment_status']): ?>
                                                    <span class="status-pill <?= pay_class($o['payment_status']) ?>"><?= $o['payment_status'] ?></span>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);font-size:.8rem;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color:var(--text-secondary);font-size:.82rem;">
                                                <?= htmlspecialchars($o['method_name'] ?? '—') ?>
                                            </td>
                                            <td>
                                                <span class="status-pill <?= status_class($o['order_status']) ?>">
                                                    <?= $o['order_status'] ?>
                                                </span>
                                            </td>
                                            <td style="color:var(--text-muted);font-size:.8rem;white-space:nowrap;">
                                                <?= date('d M Y', strtotime($o['order_date'])) ?><br>
                                                <span style="font-size:.72rem;"><?= date('h:i A', strtotime($o['order_date'])) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1):
                            $base = '?' . http_build_query(array_merge(array_filter(['search' => $f_search, 'status' => $f_status, 'customer' => $f_customer, 'date_from' => $f_date_from, 'date_to' => $f_date_to])));
                            $base .= $base ? '&' : '?';
                        ?>
                            <div class="pagination">
                                <a class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                                    href="<?= $base ?>page=<?= $page - 1 ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                    <a class="pg-btn <?= $p === $page ? 'active' : '' ?>"
                                        href="<?= $base ?>page=<?= $p ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <a class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"
                                    href="<?= $base ?>page=<?= $page + 1 ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                                <span class="pg-info">
                                    Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_rows) ?> orders
                                </span>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bag-shopping"></i>
                            <p>No orders found matching your filters.</p>
                            <a href="orders.php" class="btn-reset" style="display:inline-flex;margin-top:1rem;">
                                <i class="fas fa-rotate-left"></i> Clear Filters
                            </a>
                        </div>
                    <?php endif; ?>

                </div><!-- /table-card -->

                <!-- Back link -->
                <div style="margin-top:1.5rem;">
                    <a href="dashboard.php" class="btn-reset">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

            </div>
        </div>

        <script>
            // Auto-submit on status/date change
            document.querySelectorAll('select[name="status"]').forEach(function(el) {
                el.addEventListener('change', function() {
                    this.closest('form').submit();
                });
            });
        </script>

    </div><!-- /dashboard-main -->
    </div><!-- /dashboard-container -->
    </div><!-- /dashboard-wrapper -->

</body>

</html>