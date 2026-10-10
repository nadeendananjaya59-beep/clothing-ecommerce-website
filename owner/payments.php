<?php

/**
 * Payment Management Module - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// ─── Filters ─────────────────────────────────────────────
$f_search    = trim($_GET['search'] ?? '');
$f_status    = trim($_GET['status'] ?? '');
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to'] ?? '');

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset   = ($page - 1) * $per_page;

// ─── Summary Stats (Filtered by Shop ID via Orders) ──────
// 1. Total Payments Received (Completed)
$r = mysqli_query($conn, "SELECT COALESCE(SUM(p.amount), 0) AS total FROM payments p JOIN orders o ON p.order_id = o.order_id WHERE o.shop_id = $shop_id AND p.payment_status = 'Completed'");
$total_received = mysqli_fetch_assoc($r)['total'];

// 2. Pending Payments
$r = mysqli_query($conn, "SELECT COALESCE(SUM(p.amount), 0) AS total FROM payments p JOIN orders o ON p.order_id = o.order_id WHERE o.shop_id = $shop_id AND p.payment_status = 'Pending'");
$total_pending = mysqli_fetch_assoc($r)['total'];

// 3. Failed Payments
$r = mysqli_query($conn, "SELECT COALESCE(SUM(p.amount), 0) AS total FROM payments p JOIN orders o ON p.order_id = o.order_id WHERE o.shop_id = $shop_id AND p.payment_status = 'Failed'");
$total_failed = mysqli_fetch_assoc($r)['total'];

// 4. Refunded Payments
$r = mysqli_query($conn, "SELECT COALESCE(SUM(p.amount), 0) AS total FROM payments p JOIN orders o ON p.order_id = o.order_id WHERE o.shop_id = $shop_id AND p.payment_status = 'Refunded'");
$total_refunded = mysqli_fetch_assoc($r)['total'];

// ─── Method Wise Income ──────────────────────────────────
$methods_income = [
    'Cash On Delivery' => 0,
    'Credit Card'      => 0,
    'Debit Card'       => 0,
    'Bank Transfer'    => 0,
    'PayPal'           => 0
];

$method_sql = "SELECT pm.method_name, COALESCE(SUM(p.amount), 0) AS total 
               FROM payments p 
               JOIN orders o ON p.order_id = o.order_id 
               JOIN payment_methods pm ON p.method_id = pm.method_id 
               WHERE o.shop_id = $shop_id AND p.payment_status = 'Completed'
               GROUP BY pm.method_name";
$method_res = mysqli_query($conn, $method_sql);
if ($method_res) {
    while ($row = mysqli_fetch_assoc($method_res)) {
        $m_name = $row['method_name'];
        if (isset($methods_income[$m_name])) {
            $methods_income[$m_name] = $row['total'];
        }
    }
}
$max_method_income = max(1, max(array_values($methods_income)));

// ─── Build Query for Table ──────────────────────────────
$where = ["o.shop_id = $shop_id"];

if ($f_search !== '') {
    $search_esc = mysqli_real_escape_string($conn, $f_search);
    $where[] = "(c.full_name LIKE '%$search_esc%' OR p.payment_id LIKE '%$search_esc%' OR p.order_id LIKE '%$search_esc%' OR c.email LIKE '%$search_esc%')";
}
if ($f_status !== '') {
    $status_esc = mysqli_real_escape_string($conn, $f_status);
    $where[] = "p.payment_status = '$status_esc'";
}
if ($f_date_from !== '') {
    $date_from_esc = mysqli_real_escape_string($conn, $f_date_from);
    $where[] = "DATE(p.payment_date) >= '$date_from_esc'";
}
if ($f_date_to !== '') {
    $date_to_esc = mysqli_real_escape_string($conn, $f_date_to);
    $where[] = "DATE(p.payment_date) <= '$date_to_esc'";
}

$where_sql = implode(' AND ', $where);

// Count matching records
$count_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM payments p JOIN orders o ON p.order_id = o.order_id JOIN customers c ON o.customer_id = c.customer_id WHERE $where_sql");
$total_rows = mysqli_fetch_assoc($count_res)['c'];
$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch main payments list
$payments_sql = "SELECT p.*, o.order_date, c.full_name AS customer_name, c.email AS customer_email, pm.method_name 
                 FROM payments p 
                 JOIN orders o ON p.order_id = o.order_id 
                 JOIN customers c ON o.customer_id = c.customer_id 
                 JOIN payment_methods pm ON p.method_id = pm.method_id 
                 WHERE $where_sql 
                 ORDER BY p.payment_date DESC 
                 LIMIT $per_page OFFSET $offset";
$payments_res = mysqli_query($conn, $payments_sql);
$payments = [];
if ($payments_res) {
    while ($row = mysqli_fetch_assoc($payments_res)) {
        $payments[] = $row;
    }
}

// ─── Export/Print Mode check ──────────────────────────────
$is_report = isset($_GET['report']) && $_GET['report'] === 'true';
if ($is_report) {
    // Fetch all matched rows without limit for printing
    $print_sql = "SELECT p.*, o.order_date, c.full_name AS customer_name, c.email AS customer_email, pm.method_name 
                  FROM payments p 
                  JOIN orders o ON p.order_id = o.order_id 
                  JOIN customers c ON o.customer_id = c.customer_id 
                  JOIN payment_methods pm ON p.method_id = pm.method_id 
                  WHERE $where_sql 
                  ORDER BY p.payment_date DESC";
    $print_res = mysqli_query($conn, $print_sql);
    $print_payments = [];
    if ($print_res) {
        while ($row = mysqli_fetch_assoc($print_res)) {
            $print_payments[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        /* ── Summary Stats ── */
        .pay-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.2rem;
            margin-bottom: 2rem;
        }

        @media(max-width: 992px) {
            .pay-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width: 480px) {
            .pay-grid {
                grid-template-columns: 1fr;
            }
        }

        .pay-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.4rem;
            position: relative;
            overflow: hidden;
            transition: all var(--transition);
        }

        .pay-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }

        .pay-card::before {
            content: '';
            position: absolute;
            top: -25px;
            right: -15px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            opacity: .08;
        }

        .pc-green {
            border-top: 3px solid #10b981;
        }

        .pc-green::before {
            background: #10b981;
        }

        .pc-green .pc-icon {
            background: rgba(16, 185, 129, .15);
            color: #34d399;
        }

        .pc-amber {
            border-top: 3px solid #f59e0b;
        }

        .pc-amber::before {
            background: #f59e0b;
        }

        .pc-amber .pc-icon {
            background: rgba(245, 158, 11, .15);
            color: #fbbf24;
        }

        .pc-red {
            border-top: 3px solid #ef4444;
        }

        .pc-red::before {
            background: #ef4444;
        }

        .pc-red .pc-icon {
            background: rgba(239, 68, 68, .15);
            color: #f87171;
        }

        .pc-blue {
            border-top: 3px solid #3b82f6;
        }

        .pc-blue::before {
            background: #3b82f6;
        }

        .pc-blue .pc-icon {
            background: rgba(59, 130, 246, .15);
            color: #60a5fa;
        }

        .pc-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: .8rem;
        }

        .pc-sub {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: .2rem;
        }

        .pc-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }

        /* Method Income List */
        .methods-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .methods-title {
            font-size: .85rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-secondary);
            margin-bottom: 1.2rem;
        }

        .method-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .method-row:last-child {
            margin-bottom: 0;
        }

        .method-name {
            width: 160px;
            font-size: .875rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .method-progress-container {
            flex: 1;
            background: var(--border-light);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
        }

        .method-progress-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
        }

        .method-val {
            width: 140px;
            text-align: right;
            font-weight: 700;
            font-size: .9rem;
            color: var(--text-primary);
        }

        /* Filters */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: .4rem;
            flex: 1;
            min-width: 180px;
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
            padding: .65rem .9rem;
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

        /* Report actions */
        .report-actions {
            display: flex;
            gap: .75rem;
            justify-content: flex-end;
            margin-bottom: 1.5rem;
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

        .sp-completed {
            background: rgba(16, 185, 129, .12);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .sp-pending {
            background: rgba(245, 158, 11, .12);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, .25);
        }

        .sp-failed {
            background: rgba(239, 68, 68, .12);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, .25);
        }

        .sp-refunded {
            background: rgba(59, 130, 246, .12);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, .25);
        }

        /* General Table styling */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .table-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }

        .table-card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
        }

        .payment-table {
            width: 100%;
            border-collapse: collapse;
        }

        .payment-table th {
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

        .payment-table td {
            padding: .9rem 1rem;
            font-size: .86rem;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(255, 255, 255, .04);
            vertical-align: middle;
        }

        .payment-table tr:last-child td {
            border-bottom: none;
        }

        .payment-table tbody tr:hover td {
            background: rgba(108, 99, 255, .04);
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

        /* Print Media Styles for reports */
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
                font-size: 12px;
            }

            .topbar,
            .filter-card,
            .report-actions,
            .pagination,
            .btn-reset,
            .btn-logout,
            .user-pill {
                display: none !important;
            }

            .dashboard-wrapper {
                min-height: auto !important;
                background: transparent !important;
            }

            .dashboard-main {
                padding: 0 !important;
                max-width: 100% !important;
                margin: 0 !important;
            }

            .pay-card,
            .methods-card,
            .table-card {
                background: transparent !important;
                border: 1px solid #ddd !important;
                box-shadow: none !important;
                margin-bottom: 1.5rem !important;
            }

            .pc-val,
            .method-val,
            .payment-table td {
                color: #000 !important;
            }

            .pc-sub,
            .method-name {
                color: #555 !important;
            }

            .status-pill {
                border: 1px solid #555 !important;
                color: #000 !important;
                background: transparent !important;
            }

            .payment-table th {
                background: #eee !important;
                color: #000 !important;
                border-bottom: 1px solid #ccc !important;
            }

            .payment-table td {
                border-bottom: 1px solid #eee !important;
            }

            .method-progress-container {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <?php if ($is_report): ?>
        <!-- ═══ printable report modal style ═══ -->
        <div style="background:#fff; color:#000; padding:2rem; font-family:'Inter',sans-serif; max-width:900px; margin:2rem auto; border:1px solid #ccc; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #333; padding-bottom:1rem; margin-bottom:1.5rem;">
                <div>
                    <h1 style="margin:0; font-size:1.8rem; font-weight:800; color:#111;"><?= htmlspecialchars($shop_name) ?></h1>
                    <p style="margin:5px 0 0 0; color:#555; font-size:0.875rem;">🏪 Shop Payment Income Statement Statement</p>
                </div>
                <div style="text-align:right;">
                    <p style="margin:0; font-weight:700; color:#111;">Generated On:</p>
                    <p style="margin:3px 0 0 0; color:#555;"><?= date('d F Y, h:i A') ?></p>
                </div>
            </div>

            <h3 style="margin-top:0;">1. Financial Summary Overview</h3>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:1rem; margin-bottom:1.5rem;">
                <div style="border:1px solid #ccc; padding:1rem; border-radius:6px;">
                    <span style="font-size:0.7rem; text-transform:uppercase; color:#666; font-weight:700; display:block;">Completed Payments</span>
                    <strong style="font-size:1.2rem; display:block; margin-top:5px;">LKR <?= number_format($total_received, 2) ?></strong>
                </div>
                <div style="border:1px solid #ccc; padding:1rem; border-radius:6px;">
                    <span style="font-size:0.7rem; text-transform:uppercase; color:#666; font-weight:700; display:block;">Pending Payments</span>
                    <strong style="font-size:1.2rem; display:block; margin-top:5px;">LKR <?= number_format($total_pending, 2) ?></strong>
                </div>
                <div style="border:1px solid #ccc; padding:1rem; border-radius:6px;">
                    <span style="font-size:0.7rem; text-transform:uppercase; color:#666; font-weight:700; display:block;">Failed Payments</span>
                    <strong style="font-size:1.2rem; display:block; margin-top:5px;">LKR <?= number_format($total_failed, 2) ?></strong>
                </div>
                <div style="border:1px solid #ccc; padding:1rem; border-radius:6px;">
                    <span style="font-size:0.7rem; text-transform:uppercase; color:#666; font-weight:700; display:block;">Refunded Payments</span>
                    <strong style="font-size:1.2rem; display:block; margin-top:5px;">LKR <?= number_format($total_refunded, 2) ?></strong>
                </div>
            </div>

            <h3>2. Payment Method Performance Breakdown</h3>
            <table style="width:100%; border-collapse:collapse; margin-bottom:2rem;">
                <thead>
                    <tr style="background:#f4f4f4;">
                        <th style="text-align:left; padding:8px; border:1px solid #ddd;">Method Type</th>
                        <th style="text-align:right; padding:8px; border:1px solid #ddd;">Total Received Income</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($methods_income as $m => $total): ?>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><?= htmlspecialchars($m) ?></td>
                            <td style="text-align:right; padding:8px; border:1px solid #ddd; font-weight:700;">LKR <?= number_format($total, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3>3. Transaction Payment Statement Log</h3>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f4f4f4;">
                        <th style="text-align:left; padding:6px; border:1px solid #ddd; font-size:0.75rem;">Payment ID</th>
                        <th style="text-align:left; padding:6px; border:1px solid #ddd; font-size:0.75rem;">Order ID</th>
                        <th style="text-align:left; padding:6px; border:1px solid #ddd; font-size:0.75rem;">Customer Details</th>
                        <th style="text-align:left; padding:6px; border:1px solid #ddd; font-size:0.75rem;">Method</th>
                        <th style="text-align:right; padding:6px; border:1px solid #ddd; font-size:0.75rem;">Amount</th>
                        <th style="text-align:center; padding:6px; border:1px solid #ddd; font-size:0.75rem;">Status</th>
                        <th style="text-align:left; padding:6px; border:1px solid #ddd; font-size:0.75rem;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($print_payments)): ?>
                        <?php foreach ($print_payments as $p): ?>
                            <tr>
                                <td style="padding:6px; border:1px solid #ddd;">#<?= $p['payment_id'] ?></td>
                                <td style="padding:6px; border:1px solid #ddd;">#<?= $p['order_id'] ?></td>
                                <td style="padding:6px; border:1px solid #ddd; font-size:0.8rem;">
                                    <strong><?= htmlspecialchars($p['customer_name']) ?></strong><br>
                                    <?= htmlspecialchars($p['customer_email']) ?>
                                </td>
                                <td style="padding:6px; border:1px solid #ddd;"><?= htmlspecialchars($p['method_name']) ?></td>
                                <td style="text-align:right; padding:6px; border:1px solid #ddd; font-weight:700;">LKR <?= number_format($p['amount'], 2) ?></td>
                                <td style="text-align:center; padding:6px; border:1px solid #ddd; font-weight:700;"><?= $p['payment_status'] ?></td>
                                <td style="padding:6px; border:1px solid #ddd; font-size:0.75rem;"><?= date('Y-m-d H:i', strtotime($p['payment_date'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:1rem; border:1px solid #ddd;">No records matched standard query.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Printable Actions -->
            <div style="margin-top:2rem; display:flex; gap:1rem;" class="report-actions">
                <button onclick="window.print();" style="padding:8px 16px; background:#111; color:#fff; border:none; border-radius:4px; font-weight:700; cursor:pointer;">🖨️ Print Invoice Report</button>
                <a href="payments.php" style="padding:8px 16px; border:1px solid #aaa; border-radius:4px; text-decoration:none; color:#333; font-weight:600;">← Back to Manager</a>
            </div>
        </div>
    <?php else: ?>

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

                    <!-- Header -->
                    <div class="dashboard-header">
                        <h1>💳 Payment Management</h1>
                        <p>Monitor payments, view channel breakdown income statements, and generate printable reports.</p>
                    </div>

                    <!-- ══ Financial Summary cards ══ -->
                    <div class="pay-grid">
                        <div class="pay-card pc-green">
                            <div class="pc-icon"><i class="fas fa-circle-check"></i></div>
                            <div class="pc-sub">Payments Received</div>
                            <div class="pc-val">LKR <?= number_format($total_received, 2) ?></div>
                        </div>
                        <div class="pay-card pc-amber">
                            <div class="pc-icon"><i class="fas fa-hourglass-half"></i></div>
                            <div class="pc-sub">Pending Payments</div>
                            <div class="pc-val">LKR <?= number_format($total_pending, 2) ?></div>
                        </div>
                        <div class="pay-card pc-red">
                            <div class="pc-icon"><i class="fas fa-circle-exclamation"></i></div>
                            <div class="pc-sub">Failed Payments</div>
                            <div class="pc-val">LKR <?= number_format($total_failed, 2) ?></div>
                        </div>
                        <div class="pay-card pc-blue">
                            <div class="pc-icon"><i class="fas fa-rotate-left"></i></div>
                            <div class="pc-sub">Refunded Payments</div>
                            <div class="pc-val">LKR <?= number_format($total_refunded, 2) ?></div>
                        </div>
                    </div>

                    <!-- ══ Payment Method Wise Income ══ -->
                    <div class="methods-card">
                        <div class="methods-title"><i class="fas fa-chart-bar" style="margin-right:0.4rem; color:var(--primary-light);"></i> Income by Payment Channel</div>
                        <?php foreach ($methods_income as $method_name => $income):
                            $pct = round(($income / $max_method_income) * 100);
                        ?>
                            <div class="method-row">
                                <div class="method-name"><?= htmlspecialchars($method_name) ?></div>
                                <div class="method-progress-container">
                                    <div class="method-progress-fill" style="width: <?= $pct ?>%;"></div>
                                </div>
                                <div class="method-val">LKR <?= number_format($income, 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- ══ Filters ══ -->
                    <div class="filter-card">
                        <form method="GET" action="payments.php">
                            <div class="filter-row">
                                <div class="filter-group" style="flex:2; min-width:200px;">
                                    <label><i class="fas fa-magnifying-glass"></i> Search Payments</label>
                                    <input type="text" name="search" placeholder="Search by Customer name, email, ID..." value="<?= htmlspecialchars($f_search) ?>">
                                </div>

                                <div class="filter-group">
                                    <label><i class="fas fa-circle-check"></i> Status</label>
                                    <select name="status">
                                        <option value="">All Statuses</option>
                                        <option value="Completed" <?= $f_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="Pending" <?= $f_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Failed" <?= $f_status === 'Failed' ? 'selected' : '' ?>>Failed</option>
                                        <option value="Refunded" <?= $f_status === 'Refunded' ? 'selected' : '' ?>>Refunded</option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label><i class="fas fa-calendar-day"></i> Date From</label>
                                    <input type="date" name="date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                                </div>

                                <div class="filter-group">
                                    <label><i class="fas fa-calendar-day"></i> Date To</label>
                                    <input type="date" name="date_to" value="<?= htmlspecialchars($f_date_to) ?>">
                                </div>

                                <div style="display:flex; gap:.5rem;">
                                    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
                                    <a href="payments.php" class="btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Report Generate Action -->
                    <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
                        <?php
                        $params = array_filter(['search' => $f_search, 'status' => $f_status, 'date_from' => $f_date_from, 'date_to' => $f_date_to]);
                        $params['report'] = 'true';
                        $report_url = 'payments.php?' . http_build_query($params);
                        ?>
                        <a href="<?= htmlspecialchars($report_url) ?>" class="btn-filter" style="text-decoration:none;"><i class="fas fa-file-invoice-dollar"></i> Generate Financial Statement Report</a>
                    </div>

                    <!-- ══ Payments Listing Table ══ -->
                    <div class="table-card">
                        <div class="table-card-header">
                            <h2>Payment Transactions</h2>
                            <span class="count-badge" style="background:rgba(108,99,255,.15); color:var(--primary-light); padding:.2rem .65rem; border-radius:20px; font-size:.75rem; font-weight:700; border:1px solid rgba(108,99,255,.25);"><?= number_format($total_rows) ?> transaction(s)</span>
                        </div>

                        <?php if (!empty($payments)): ?>
                            <div style="overflow-x:auto;">
                                <table class="payment-table">
                                    <thead>
                                        <tr>
                                            <th>Payment ID</th>
                                            <th>Order ID</th>
                                            <th>Customer Details</th>
                                            <th>Method</th>
                                            <th style="text-align:right;">Amount Paid</th>
                                            <th style="text-align:center;">Status</th>
                                            <th>Transaction Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $p): ?>
                                            <tr>
                                                <td style="color:var(--primary-light); font-weight:700;">#<?= $p['payment_id'] ?></td>
                                                <td style="color:var(--text-muted);">#<?= $p['order_id'] ?></td>
                                                <td>
                                                    <strong><?= htmlspecialchars($p['customer_name']) ?></strong><br>
                                                    <span style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($p['customer_email']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($p['method_name']) ?></td>
                                                <td style="text-align:right; font-weight:700; color:var(--text-primary);">LKR <?= number_format($p['amount'], 2) ?></td>
                                                <td style="text-align:center;">
                                                    <span class="status-pill sp-<?= strtolower($p['payment_status']) ?>">
                                                        <?= $p['payment_status'] ?>
                                                    </span>
                                                </td>
                                                <td style="color:var(--text-muted); font-size:0.8rem;">
                                                    <?= date('d M Y', strtotime($p['payment_date'])) ?><br>
                                                    <span style="font-size:0.72rem;"><?= date('h:i A', strtotime($p['payment_date'])) ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <?php
                                $params = array_filter(['search' => $f_search, 'status' => $f_status, 'date_from' => $f_date_from, 'date_to' => $f_date_to]);
                                $query_string = http_build_query($params);
                                $base_url = 'payments.php?' . ($query_string ? $query_string . '&' : '');
                                ?>
                                <div class="pagination">
                                    <a class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                        <a class="pg-btn <?= $p === $page ? 'active' : '' ?>" href="<?= $base_url ?>page=<?= $p ?>"><?= $p ?></a>
                                    <?php endfor; ?>
                                    <a class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                                    <span class="pg-info">Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_rows) ?> payments</span>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div style="text-align:center; padding:3rem 2rem;">
                                <i class="fas fa-receipt" style="font-size:3rem; color:var(--text-muted); margin-bottom:1rem; display:block;"></i>
                                <p style="color:var(--text-muted); margin:0;">No transactions found match your filter params.</p>
                                <a href="payments.php" class="btn-reset" style="display:inline-flex; margin-top:1rem;"><i class="fas fa-rotate-left"></i> Clear Filters</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Back to Dashboard -->
                    <div style="margin-top: 2rem;">
                        <a href="dashboard.php" class="btn-reset" style="display:inline-flex;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    </div>

                </div>
            </div>

        <?php endif; ?>

        <script>
            // Auto-submit dropdown selector on change
            document.querySelectorAll('.filter-group select').forEach(function(select) {
                select.addEventListener('change', function() {
                    this.closest('form').submit();
                });
            });
        </script>
</body>

</html>