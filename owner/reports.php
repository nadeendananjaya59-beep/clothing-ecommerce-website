<?php

/**
 * Reports Management Module - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// ─── Filter parameters ───────────────────────────────────
$f_type      = trim($_GET['type'] ?? 'sales');
$f_date_from = trim($_GET['date_from'] ?? date('Y-m-01')); // default to start of month
$f_date_to   = trim($_GET['date_to'] ?? date('Y-m-d'));

$date_from_esc = mysqli_real_escape_string($conn, $f_date_from);
$date_to_esc   = mysqli_real_escape_string($conn, $f_date_to);

$report_title = '';
$headers = [];
$rows = [];
$totals = [];

// ─── Generate Report Logic ────────────────────────────────
switch ($f_type) {
    case 'sales':
        $report_title = "Sales Summary Report ($f_date_from to $f_date_to)";
        $headers = ['Date', 'Orders Count', 'Avg Order Value (LKR)', 'Gross Revenue (LKR)'];

        $sql = "SELECT DATE(order_date) AS r_date, 
                       COUNT(order_id) AS orders_count,
                       AVG(total_amount) AS avg_amount,
                       SUM(total_amount) AS total_rev
                FROM orders 
                WHERE shop_id = $shop_id 
                  AND DATE(order_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
                  AND order_status != 'Cancelled'
                GROUP BY DATE(order_date)
                ORDER BY r_date DESC";
        $res = mysqli_query($conn, $sql);

        $t_orders = 0;
        $t_rev = 0;
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    $r['r_date'],
                    number_format($r['orders_count']),
                    'LKR ' . number_format($r['avg_amount'], 2),
                    'LKR ' . number_format($r['total_rev'], 2)
                ];
                $t_orders += $r['orders_count'];
                $t_rev += $r['total_rev'];
            }
        }
        $totals = [
            'Total',
            number_format($t_orders),
            '—',
            'LKR ' . number_format($t_rev, 2)
        ];
        break;

    case 'product':
        $report_title = "Product Performance Report ($f_date_from to $f_date_to)";
        $headers = ['Prod ID', 'Product Name', 'Category', 'Selling Price', 'Units Sold', 'Total Revenue (LKR)'];

        $sql = "SELECT p.product_id, p.product_name, c.category_name, p.selling_price,
                       COALESCE(SUM(oi.quantity), 0) AS units_sold,
                       COALESCE(SUM(oi.subtotal), 0) AS product_rev
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                LEFT JOIN order_items oi ON p.product_id = oi.product_id
                LEFT JOIN orders o ON oi.order_id = o.order_id
                WHERE p.shop_id = $shop_id
                  AND (o.order_id IS NULL OR (DATE(o.order_date) BETWEEN '$date_from_esc' AND '$date_to_esc' AND o.order_status != 'Cancelled'))
                GROUP BY p.product_id
                ORDER BY units_sold DESC";
        $res = mysqli_query($conn, $sql);

        $t_units = 0;
        $t_revenue = 0;
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    '#' . $r['product_id'],
                    $r['product_name'],
                    $r['category_name'] ?? '—',
                    'LKR ' . number_format($r['selling_price'], 2),
                    number_format($r['units_sold']),
                    'LKR ' . number_format($r['product_rev'], 2)
                ];
                $t_units += $r['units_sold'];
                $t_revenue += $r['product_rev'];
            }
        }
        $totals = ['Total', '', '', '', number_format($t_units), 'LKR ' . number_format($t_revenue, 2)];
        break;

    case 'stock':
        $report_title = "Current Inventory Stock Report";
        $headers = ['Prod ID', 'Product Name', 'Category', 'Cost Price', 'Selling Price', 'Current Stock', 'Status'];

        $sql = "SELECT p.product_id, p.product_name, c.category_name, p.cost_price, p.selling_price, p.stock_qty, p.status
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE p.shop_id = $shop_id
                ORDER BY p.stock_qty ASC";
        $res = mysqli_query($conn, $sql);

        $t_stock = 0;
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    '#' . $r['product_id'],
                    $r['product_name'],
                    $r['category_name'] ?? '—',
                    'LKR ' . number_format($r['cost_price'], 2),
                    'LKR ' . number_format($r['selling_price'], 2),
                    number_format($r['stock_qty']),
                    $r['status']
                ];
                $t_stock += $r['stock_qty'];
            }
        }
        $totals = ['Total Products: ' . count($rows), '', '', '', '', number_format($t_stock) . ' Units', ''];
        break;

    case 'customer':
        $report_title = "Customer Spend Report ($f_date_from to $f_date_to)";
        $headers = ['Cust ID', 'Customer Name', 'Email', 'Orders count', 'Lifetime Spend (LKR)'];

        $sql = "SELECT c.customer_id, c.full_name, c.email,
                       COUNT(o.order_id) AS orders_count,
                       SUM(o.total_amount) AS total_spend
                FROM customers c
                JOIN orders o ON c.customer_id = o.customer_id
                WHERE o.shop_id = $shop_id
                  AND DATE(o.order_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
                  AND o.order_status != 'Cancelled'
                GROUP BY c.customer_id
                ORDER BY total_spend DESC";
        $res = mysqli_query($conn, $sql);

        $t_spend = 0;
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    '#' . $r['customer_id'],
                    $r['full_name'],
                    $r['email'],
                    number_format($r['orders_count']),
                    'LKR ' . number_format($r['total_spend'], 2)
                ];
                $t_spend += $r['total_spend'];
            }
        }
        $totals = ['Total', '', '', '', 'LKR ' . number_format($t_spend, 2)];
        break;

    case 'order':
        $report_title = "Orders Log Statement ($f_date_from to $f_date_to)";
        $headers = ['Order ID', 'Order Date', 'Customer Name', 'Total Amount (LKR)', 'Fulfillment Status'];

        $sql = "SELECT o.order_id, o.order_date, c.full_name, o.total_amount, o.order_status
                FROM orders o
                JOIN customers c ON o.customer_id = c.customer_id
                WHERE o.shop_id = $shop_id
                  AND DATE(o.order_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
                ORDER BY o.order_date DESC";
        $res = mysqli_query($conn, $sql);

        $t_amt = 0;
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    '#' . $r['order_id'],
                    date('d M Y, h:i A', strtotime($r['order_date'])),
                    $r['full_name'],
                    'LKR ' . number_format($r['total_amount'], 2),
                    $r['order_status']
                ];
                $t_amt += $r['total_amount'];
            }
        }
        $totals = ['Total Orders: ' . count($rows), '', '', 'LKR ' . number_format($t_amt, 2), ''];
        break;

    case 'payment':
        $report_title = "Payments Transaction Statement ($f_date_from to $f_date_to)";
        $headers = ['Payment ID', 'Payment Date', 'Order ID', 'Payment Method', 'Amount (LKR)', 'Payment Status'];

        $sql = "SELECT p.payment_id, p.payment_date, p.order_id, pm.method_name, p.amount, p.payment_status
                FROM payments p
                JOIN orders o ON p.order_id = o.order_id
                JOIN payment_methods pm ON p.method_id = pm.method_id
                WHERE o.shop_id = $shop_id
                  AND DATE(p.payment_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
                ORDER BY p.payment_date DESC";
        $res = mysqli_query($conn, $sql);

        $t_pay = 0;
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    '#' . $r['payment_id'],
                    date('d M Y, h:i A', strtotime($r['payment_date'])),
                    '#' . $r['order_id'],
                    $r['method_name'],
                    'LKR ' . number_format($r['amount'], 2),
                    $r['payment_status']
                ];
                if ($r['payment_status'] === 'Completed') {
                    $t_pay += $r['amount'];
                }
            }
        }
        $totals = ['Total Completed Payments', '', '', '', 'LKR ' . number_format($t_pay, 2), ''];
        break;

    case 'feedback':
        $report_title = "Product Reviews & Ratings Report ($f_date_from to $f_date_to)";
        $headers = ['Feedback ID', 'Date', 'Product Name', 'Customer Name', 'Rating Score', 'Status'];

        $sql = "SELECT uf.feedback_id, uf.feedback_date, p.product_name, c.full_name, uf.rating, uf.status
                FROM user_feedback uf
                JOIN products p ON uf.product_id = p.product_id
                JOIN customers c ON uf.customer_id = c.customer_id
                WHERE p.shop_id = $shop_id
                  AND DATE(uf.feedback_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
                ORDER BY uf.feedback_date DESC";
        $res = mysqli_query($conn, $sql);

        $t_stars = 0;
        $c_count = 0;
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    '#' . $r['feedback_id'],
                    date('d M Y', strtotime($r['feedback_date'])),
                    $r['product_name'],
                    $r['full_name'],
                    $r['rating'] . ' / 5',
                    $r['status']
                ];
                $t_stars += $r['rating'];
                $c_count++;
            }
        }
        $avg_stars = $c_count > 0 ? number_format($t_stars / $c_count, 2) . ' / 5 Avg' : '—';
        $totals = ['Total Reviews: ' . $c_count, '', '', '', $avg_stars, ''];
        break;

    case 'lowstock':
        $report_title = "Low Stock Alert Report";
        $headers = ['Prod ID', 'Product Name', 'Category', 'Current Stock', 'Reorder Level', 'Status'];

        $sql = "SELECT p.product_id, p.product_name, c.category_name, p.stock_qty, p.reorder_level, p.status
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE p.shop_id = $shop_id AND p.stock_qty <= p.reorder_level
                ORDER BY p.stock_qty ASC";
        $res = mysqli_query($conn, $sql);

        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = [
                    '#' . $r['product_id'],
                    $r['product_name'],
                    $r['category_name'] ?? '—',
                    number_format($r['stock_qty']),
                    number_format($r['reorder_level']),
                    $r['stock_qty'] == 0 ? 'Out of Stock' : 'Low Stock'
                ];
            }
        }
        $totals = ['Total Low Stock items: ' . count($rows), '', '', '', '', ''];
        break;
}

// ─── CSV Export Trigger ──────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $f_type)) . '_report_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');

    // Title & Info block
    fputcsv($output, [$report_title]);
    fputcsv($output, ["Generated by Shop: " . $shop_name]);
    fputcsv($output, ["Date Generated: " . date('Y-m-d h:i A')]);
    fputcsv($output, []); // blank line

    // Headers
    fputcsv($output, $headers);

    // Rows
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    // Totals
    if (!empty($totals)) {
        fputcsv($output, []);
        fputcsv($output, $totals);
    }

    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Statements | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        .report-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 1.5rem;
        }

        @media(max-width: 900px) {
            .report-layout {
                grid-template-columns: 1fr;
            }
        }

        .report-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: fit-content;
        }

        .report-card h2 {
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            margin-bottom: 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            padding-bottom: 0.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 1rem;
        }

        .form-group label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        .form-group select,
        .form-group input {
            background: var(--bg-input);
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.875rem;
            padding: 0.65rem;
            outline: none;
            font-family: 'Inter', sans-serif;
        }

        .form-group select option {
            background: #1a1a2e;
        }

        .btn-gen {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: none;
            padding: 0.75rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            transition: all var(--transition);
        }

        .btn-gen:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 99, 255, 0.4);
        }

        /* Preview area */
        .preview-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 2rem;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 1rem;
        }

        .preview-header-left h3 {
            font-size: 1.2rem;
            font-weight: 800;
            margin: 0 0 0.3rem 0;
            color: var(--text-primary);
        }

        .preview-header-left p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .action-bar {
            display: flex;
            gap: 0.5rem;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
            transition: all var(--transition);
            border: 1.5px solid var(--border-light);
            background: transparent;
            color: var(--text-secondary);
        }

        .btn-action:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .report-table th {
            text-align: left;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 700;
            padding: 0.75rem 1rem;
            border-bottom: 2px solid var(--border-light);
            background: rgba(255, 255, 255, 0.01);
        }

        .report-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            color: var(--text-primary);
        }

        .report-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .report-table tr.total-row td {
            border-top: 2px solid var(--border-light);
            border-bottom: double 3px var(--border-light);
            font-weight: 700;
            color: var(--text-primary);
            background: rgba(108, 99, 255, 0.05);
        }

        /* ── Print Media Query Style ── */
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
                font-family: Arial, sans-serif !important;
            }

            .topbar,
            .report-card,
            .action-bar,
            .btn-reset,
            .dashboard-header {
                display: none !important;
            }

            .dashboard-wrapper,
            .dashboard-main,
            .preview-card {
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
                background: none !important;
                box-shadow: none !important;
                display: block !important;
            }

            .report-table th {
                color: #000 !important;
                border-bottom: 2px solid #000 !important;
                background: #f3f4f6 !important;
            }

            .report-table td {
                color: #000 !important;
                border-bottom: 1px solid #ddd !important;
            }

            .report-table tr.total-row td {
                border-top: 2px solid #000 !important;
                border-bottom: double 3px #000 !important;
                background: #f3f4f6 !important;
            }

            .preview-header {
                border-bottom: 2px solid #000 !important;
                margin-bottom: 20px !important;
            }

            .preview-header-left h3 {
                color: #000 !important;
                font-size: 1.5rem !important;
            }
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

                <!-- Header -->
                <div class="dashboard-header">
                    <h1>📊 Reports & Statements</h1>
                <p>Generate financial statements, stock alerts, sales analytics, and feedback logs for bookkeeping.</p>
            </div>

            <!-- Layout Grid -->
            <div class="report-layout">

                <!-- Sidebar Form controls -->
                <div class="report-card">
                    <h2>Report Parameters</h2>
                    <form method="GET" action="reports.php">
                        <div class="form-group">
                            <label>Report Type</label>
                            <select name="type" id="reportTypeSelector">
                                <option value="sales" <?= $f_type === 'sales' ? 'selected' : '' ?>>Sales Summary</option>
                                <option value="product" <?= $f_type === 'product' ? 'selected' : '' ?>>Product Performance</option>
                                <option value="stock" <?= $f_type === 'stock' ? 'selected' : '' ?>>Stock Inventory</option>
                                <option value="customer" <?= $f_type === 'customer' ? 'selected' : '' ?>>Customer Spend</option>
                                <option value="order" <?= $f_type === 'order' ? 'selected' : '' ?>>Orders Log</option>
                                <option value="payment" <?= $f_type === 'payment' ? 'selected' : '' ?>>Payment Transactions</option>
                                <option value="feedback" <?= $f_type === 'feedback' ? 'selected' : '' ?>>Product Reviews</option>
                                <option value="lowstock" <?= $f_type === 'lowstock' ? 'selected' : '' ?>>Low Stock Alerts</option>
                            </select>
                        </div>

                        <div class="form-group date-filter-group">
                            <label>Date From</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($f_date_from) ?>">
                        </div>

                        <div class="form-group date-filter-group">
                            <label>Date To</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($f_date_to) ?>">
                        </div>

                        <button type="submit" class="btn-gen"><i class="fas fa-arrows-rotate"></i> Generate Report</button>
                    </form>
                </div>

                <!-- Main Preview Statement -->
                <div class="preview-card">

                    <div class="preview-header">
                        <div class="preview-header-left">
                            <h3><?= htmlspecialchars($report_title) ?></h3>
                            <p>Shop Tenant: <?= htmlspecialchars($shop_name) ?> &nbsp;·&nbsp; Generated: <?= date('d M Y, h:i A') ?></p>
                        </div>
                        <div class="action-bar">
                            <button onclick="window.print()" class="btn-action"><i class="fas fa-print"></i> Print / Save PDF</button>
                            <?php
                            $export_params = $_GET;
                            $export_params['export'] = 'csv';
                            $csv_url = 'reports.php?' . http_build_query($export_params);
                            ?>
                            <a href="<?= $csv_url ?>" class="btn-action"><i class="fas fa-file-excel"></i> Export to Excel</a>
                        </div>
                    </div>

                    <!-- Preview Data Table -->
                    <?php if (!empty($rows)): ?>
                        <div style="overflow-x:auto;">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <?php foreach ($headers as $h): ?>
                                            <th><?= htmlspecialchars($h) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $val): ?>
                                                <td><?= htmlspecialchars($val) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>

                                    <!-- Totals Summary Row -->
                                    <?php if (!empty($totals)): ?>
                                        <tr class="total-row">
                                            <?php foreach ($totals as $t): ?>
                                                <td><?= htmlspecialchars($t) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; padding:5rem 2rem; color:var(--text-muted);">
                            <i class="fas fa-file-invoice-dollar" style="font-size:3rem; margin-bottom:1rem; display:block;"></i>
                            <p>No transaction or catalog logs found within this parameters range.</p>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

            <!-- Back to Dashboard Button -->
            <div style="margin-top: 2rem;">
                <a href="dashboard.php" class="btn-reset" style="display:inline-flex;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>

        </div>
    </div>

    <script>
        // Hide date range selectors for stock reports where date parameters are not applicable
        const typeSelector = document.getElementById('reportTypeSelector');
        const dateGroups = document.querySelectorAll('.date-filter-group');

        function toggleDateFilters() {
            const val = typeSelector.value;
            if (val === 'stock' || val === 'lowstock') {
                dateGroups.forEach(g => g.style.display = 'none');
            } else {
                dateGroups.forEach(g => g.style.display = 'flex');
            }
        }

        typeSelector.addEventListener('change', toggleDateFilters);
        document.addEventListener('DOMContentLoaded', toggleDateFilters);
    </script>
</body>

</html>