<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_helpers.php';

require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id = (int) ($_SESSION['owner_shop_id'] ?? 0);
$initials = product_module_owner_initials($owner_name);

$schema_issues = $shop_id > 0
    ? product_module_performance_schema_issues($conn)
    : ['Your account is not linked to a shop yet.'];

$available_years = $shop_id > 0 && empty($schema_issues)
    ? product_module_fetch_available_order_years($conn, $shop_id)
    : [(int) date('Y')];

$requested_year = isset($_GET['year']) && ctype_digit((string) $_GET['year'])
    ? (int) $_GET['year']
    : (int) ($available_years[0] ?? date('Y'));
$selected_year = in_array($requested_year, $available_years, true)
    ? $requested_year
    : (int) ($available_years[0] ?? date('Y'));

$allowed_limits = [5, 10, 15, 20];
$requested_limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
$display_limit = in_array($requested_limit, $allowed_limits, true) ? $requested_limit : 10;

$product_rows = [];
$monthly_sales_report = ['months' => [], 'leaders' => []];
$monthly_rows = [];
$best_selling_rows = [];
$low_selling_rows = [];
$revenue_rows = [];
$order_count_rows = [];
$ranking_rows = [];
$summary = [
    'catalog_products' => 0,
    'products_with_sales' => 0,
    'total_units_sold' => 0,
    'total_revenue' => 0.0,
    'total_orders' => 0,
    'average_revenue_per_product' => 0.0
];
$top_seller_name = 'No sales data yet';
$top_revenue_name = 'No revenue data yet';

if (empty($schema_issues)) {
    $product_rows = product_module_fetch_product_performance_rows($conn, $shop_id, $selected_year);
    $monthly_sales_report = product_module_fetch_monthly_product_sales($conn, $shop_id, $selected_year);

    $summary['catalog_products'] = count($product_rows);

    foreach ($product_rows as $row) {
        $summary['total_units_sold'] += (int) $row['units_sold'];
        $summary['total_revenue'] += (float) $row['revenue'];

        if ((int) $row['units_sold'] > 0 || (float) $row['revenue'] > 0 || (int) $row['order_count'] > 0) {
            $summary['products_with_sales']++;
        }
    }

    for ($month = 1; $month <= 12; $month++) {
        $month_key = sprintf('%04d-%02d', $selected_year, $month);
        $base_row = $monthly_sales_report['months'][$month_key] ?? [
            'month_key' => $month_key,
            'month_number' => $month,
            'order_count' => 0,
            'product_count' => 0,
            'units_sold' => 0,
            'revenue' => 0
        ];
        $leader = $monthly_sales_report['leaders'][$month_key] ?? null;

        $monthly_rows[] = [
            'month_key' => $month_key,
            'month_label' => date('M Y', mktime(0, 0, 0, $month, 1, $selected_year)),
            'order_count' => (int) ($base_row['order_count'] ?? 0),
            'product_count' => (int) ($base_row['product_count'] ?? 0),
            'units_sold' => (int) ($base_row['units_sold'] ?? 0),
            'revenue' => (float) ($base_row['revenue'] ?? 0),
            'leader_name' => $leader['product_name'] ?? 'No sales',
            'leader_units_sold' => (int) ($leader['units_sold'] ?? 0)
        ];
    }

    foreach ($monthly_rows as $monthly_row) {
        $summary['total_orders'] += (int) $monthly_row['order_count'];
    }

    if ($summary['products_with_sales'] > 0) {
        $summary['average_revenue_per_product'] = $summary['total_revenue'] / $summary['products_with_sales'];
    }

    $sold_rows = array_values(array_filter($product_rows, function($row) {
        return (int) $row['units_sold'] > 0 || (float) $row['revenue'] > 0 || (int) $row['order_count'] > 0;
    }));

    $best_selling_rows = $sold_rows;
    usort($best_selling_rows, function($left, $right) {
        return
            ((int) $right['units_sold'] <=> (int) $left['units_sold']) ?:
            ((float) $right['revenue'] <=> (float) $left['revenue']) ?:
            ((int) $right['order_count'] <=> (int) $left['order_count']) ?:
            strcasecmp((string) $left['product_name'], (string) $right['product_name']);
    });

    $revenue_rows = $sold_rows;
    usort($revenue_rows, function($left, $right) {
        return
            ((float) $right['revenue'] <=> (float) $left['revenue']) ?:
            ((int) $right['units_sold'] <=> (int) $left['units_sold']) ?:
            ((int) $right['order_count'] <=> (int) $left['order_count']) ?:
            strcasecmp((string) $left['product_name'], (string) $right['product_name']);
    });

    $order_count_rows = $sold_rows;
    usort($order_count_rows, function($left, $right) {
        return
            ((int) $right['order_count'] <=> (int) $left['order_count']) ?:
            ((int) $right['units_sold'] <=> (int) $left['units_sold']) ?:
            ((float) $right['revenue'] <=> (float) $left['revenue']) ?:
            strcasecmp((string) $left['product_name'], (string) $right['product_name']);
    });

    $low_selling_rows = $product_rows;
    usort($low_selling_rows, function($left, $right) {
        return
            ((int) $left['units_sold'] <=> (int) $right['units_sold']) ?:
            ((float) $left['revenue'] <=> (float) $right['revenue']) ?:
            ((int) $left['order_count'] <=> (int) $right['order_count']) ?:
            strcasecmp((string) $left['product_name'], (string) $right['product_name']);
    });

    $max_units_sold = 0;
    $max_revenue = 0.0;
    $max_order_count = 0;
    foreach ($product_rows as $row) {
        $max_units_sold = max($max_units_sold, (int) $row['units_sold']);
        $max_revenue = max($max_revenue, (float) $row['revenue']);
        $max_order_count = max($max_order_count, (int) $row['order_count']);
    }

    foreach ($product_rows as $row) {
        $score = 0;
        if ($max_revenue > 0) {
            $score += ((float) $row['revenue'] / $max_revenue) * 45;
        }
        if ($max_units_sold > 0) {
            $score += ((int) $row['units_sold'] / $max_units_sold) * 35;
        }
        if ($max_order_count > 0) {
            $score += ((int) $row['order_count'] / $max_order_count) * 20;
        }

        $row['performance_score'] = round($score, 1);
        $ranking_rows[] = $row;
    }

    usort($ranking_rows, function($left, $right) {
        return
            ((float) $right['performance_score'] <=> (float) $left['performance_score']) ?:
            ((float) $right['revenue'] <=> (float) $left['revenue']) ?:
            ((int) $right['units_sold'] <=> (int) $left['units_sold']) ?:
            ((int) $right['order_count'] <=> (int) $left['order_count']) ?:
            strcasecmp((string) $left['product_name'], (string) $right['product_name']);
    });

    foreach ($ranking_rows as $index => $ranking_row) {
        $ranking_rows[$index]['rank'] = $index + 1;
    }

    $top_seller_name = !empty($best_selling_rows) ? (string) $best_selling_rows[0]['product_name'] : 'No sales data yet';
    $top_revenue_name = !empty($revenue_rows) ? (string) $revenue_rows[0]['product_name'] : 'No revenue data yet';
}

$chart_best_selling_rows = array_slice($best_selling_rows, 0, $display_limit);
$chart_revenue_rows = array_slice($revenue_rows, 0, $display_limit);

$top_selling_chart_labels = [];
$top_selling_chart_values = [];
foreach ($chart_best_selling_rows as $row) {
    $top_selling_chart_labels[] = product_module_safe_strlen($row['product_name']) > 24
        ? substr($row['product_name'], 0, 21) . '...'
        : $row['product_name'];
    $top_selling_chart_values[] = (int) $row['units_sold'];
}

$revenue_chart_labels = [];
$revenue_chart_values = [];
foreach ($chart_revenue_rows as $row) {
    $revenue_chart_labels[] = product_module_safe_strlen($row['product_name']) > 24
        ? substr($row['product_name'], 0, 21) . '...'
        : $row['product_name'];
    $revenue_chart_values[] = round((float) $row['revenue'], 2);
}

if (empty($top_selling_chart_labels)) {
    $top_selling_chart_labels = ['No sales data'];
    $top_selling_chart_values = [0];
}

if (empty($revenue_chart_labels)) {
    $revenue_chart_labels = ['No revenue data'];
    $revenue_chart_values = [0];
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Performance Analytics | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <link rel="stylesheet" href="assets/css/product-management.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <div class="topbar">
        <a href="dashboard.php" class="topbar-brand">
            <div class="t-icon"><i class="fas fa-store"></i></div>
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

            <main class="dashboard-main">
                <div class="dashboard-header mb-0">
                    <h1>Product Performance Analytics</h1>
                    <p>Measure best sellers, low sellers, revenue contribution, order volume, and month-by-month product sales from one shop-scoped dashboard.</p>
                </div>

                <div class="module-backbar">
                    <div class="module-actions">
                        <a href="products.php" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left"></i> Back to Products
                        </a>
                        <a href="analytics.php" class="btn btn-outline-light">
                            <i class="fas fa-chart-pie"></i> General Analytics
                        </a>
                        <a href="orders.php" class="btn btn-outline-info">
                            <i class="fas fa-bag-shopping"></i> View Orders
                        </a>
                    </div>
                    <span class="module-pill"><i class="fas fa-shop"></i> Shop #<?= (int) $shop_id ?></span>
                </div>

                <?php if (!empty($schema_issues)): ?>
                    <div class="alert alert-warning module-alert" role="alert">
                        <div class="d-flex align-items-start gap-3">
                            <i class="fas fa-triangle-exclamation mt-1"></i>
                            <div>
                                <strong>Schema setup required.</strong>
                                <p class="mb-2">The product performance module needs product and order tables ready before analytics can run safely.</p>
                                <ul class="mb-0">
                                    <?php foreach ($schema_issues as $issue): ?>
                                        <li><?= htmlspecialchars($issue) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <section class="module-hero">
                        <div>
                            <h2>Performance view for <?= htmlspecialchars((string) $selected_year) ?></h2>
                            <p>Every metric below is filtered by the logged-in owner's `shop_id`, excludes cancelled orders, and reads directly from `products`, `orders`, and `order_items`.</p>
                        </div>
                        <div class="module-hero-actions">
                            <span class="module-pill"><i class="fas fa-calendar"></i> Report year <?= (int) $selected_year ?></span>
                            <span class="module-pill"><i class="fas fa-fire"></i> Best seller: <?= htmlspecialchars(product_module_excerpt($top_seller_name, 28)) ?></span>
                            <span class="module-pill"><i class="fas fa-sack-dollar"></i> Revenue leader: <?= htmlspecialchars(product_module_excerpt($top_revenue_name, 28)) ?></span>
                        </div>
                    </section>

                    <section class="module-card">
                        <div class="module-card-header">
                            <div>
                                <h2>Report Controls</h2>
                                <p class="module-card-subtitle mb-0">Switch the year or how many products you want highlighted in the charts and leaderboards.</p>
                            </div>
                        </div>

                        <form method="GET" action="product_performance.php">
                            <div class="module-filter-grid performance-filter-grid">
                                <div>
                                    <label class="form-label" for="reportYearSelect">Report Year</label>
                                    <select class="form-select" id="reportYearSelect" name="year" data-submit-on-change="true">
                                        <?php foreach ($available_years as $year_option): ?>
                                            <option value="<?= (int) $year_option ?>" <?= $selected_year === (int) $year_option ? 'selected' : '' ?>>
                                                <?= (int) $year_option ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="leaderboardLimitSelect">Highlight Count</label>
                                    <select class="form-select" id="leaderboardLimitSelect" name="limit" data-submit-on-change="true">
                                        <?php foreach ($allowed_limits as $limit_option): ?>
                                            <option value="<?= (int) $limit_option ?>" <?= $display_limit === (int) $limit_option ? 'selected' : '' ?>>
                                                Top <?= (int) $limit_option ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="performance-filter-note">
                                    <span class="module-meta-label">Analysis Scope</span>
                                    <strong><?= number_format($summary['catalog_products']) ?> catalog products</strong>
                                    <span><?= number_format($summary['products_with_sales']) ?> products generated sales in <?= (int) $selected_year ?></span>
                                </div>
                                <div class="d-flex align-items-end gap-2">
                                    <button type="submit" class="btn btn-light module-accent-btn">
                                        <i class="fas fa-rotate"></i> Refresh
                                    </button>
                                    <a href="product_performance.php" class="btn btn-outline-light">Reset</a>
                                </div>
                            </div>
                        </form>
                    </section>

                    <section class="module-stats">
                        <article class="module-stat stat-primary">
                            <div class="module-stat-icon"><i class="fas fa-sack-dollar"></i></div>
                            <div class="module-stat-value"><?= htmlspecialchars(product_module_price_label($summary['total_revenue'])) ?></div>
                            <div class="module-stat-label">Product Revenue</div>
                        </article>
                        <article class="module-stat stat-success">
                            <div class="module-stat-icon"><i class="fas fa-cart-flatbed"></i></div>
                            <div class="module-stat-value"><?= number_format($summary['total_units_sold']) ?></div>
                            <div class="module-stat-label">Units Sold</div>
                        </article>
                        <article class="module-stat stat-warning">
                            <div class="module-stat-icon"><i class="fas fa-receipt"></i></div>
                            <div class="module-stat-value"><?= number_format($summary['total_orders']) ?></div>
                            <div class="module-stat-label">Orders Count</div>
                        </article>
                        <article class="module-stat stat-danger">
                            <div class="module-stat-icon"><i class="fas fa-chart-line"></i></div>
                            <div class="module-stat-value"><?= htmlspecialchars(product_module_price_label($summary['average_revenue_per_product'])) ?></div>
                            <div class="module-stat-label">Avg Revenue per Selling Product</div>
                        </article>
                    </section>

                    <?php if ($summary['products_with_sales'] === 0): ?>
                        <div class="alert alert-info module-alert" role="alert">
                            There are no non-cancelled product sales for <?= (int) $selected_year ?> yet. The tables below still show your current catalog so you can spot products with no movement.
                        </div>
                    <?php endif; ?>

                    <div class="performance-chart-grid">
                        <section class="module-card performance-chart-card">
                            <div class="module-card-header">
                                <div>
                                    <h2>Top Selling Products Chart</h2>
                                    <p class="module-card-subtitle mb-0">Units sold by product for the top <?= (int) $display_limit ?> performers.</p>
                                </div>
                            </div>
                            <div class="performance-chart-canvas">
                                <canvas id="topSellingProductsChart"></canvas>
                            </div>
                        </section>

                        <section class="module-card performance-chart-card">
                            <div class="module-card-header">
                                <div>
                                    <h2>Product Revenue Chart</h2>
                                    <p class="module-card-subtitle mb-0">Revenue contribution by product for the top <?= (int) $display_limit ?> revenue drivers.</p>
                                </div>
                            </div>
                            <div class="performance-chart-canvas">
                                <canvas id="productRevenueChart"></canvas>
                            </div>
                        </section>
                    </div>

                    <div class="row g-4">
                        <div class="col-xl-6">
                            <section class="module-card module-table-card h-100">
                                <div class="module-table-head">
                                    <div>
                                        <h2>Best Selling Products</h2>
                                        <p class="module-card-subtitle mb-0">Top movers ranked by units sold, then revenue and order count.</p>
                                    </div>
                                </div>

                                <?php if (!empty($best_selling_rows)): ?>
                                    <div class="module-table-wrap">
                                        <div class="table-responsive">
                                            <table class="table table-hover module-table table-stack">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Units Sold</th>
                                                        <th>Revenue</th>
                                                        <th>Orders</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($best_selling_rows, 0, $display_limit) as $row): ?>
                                                        <tr>
                                                            <td data-label="Product">
                                                                <div class="product-name"><?= htmlspecialchars($row['product_name']) ?></div>
                                                                <div class="product-subline"><?= htmlspecialchars($row['category_name']) ?> &middot; <?= htmlspecialchars($row['brand_name']) ?></div>
                                                            </td>
                                                            <td data-label="Units Sold"><?= number_format((int) $row['units_sold']) ?></td>
                                                            <td data-label="Revenue"><?= htmlspecialchars(product_module_price_label($row['revenue'])) ?></td>
                                                            <td data-label="Orders"><?= number_format((int) $row['order_count']) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="module-empty">
                                        <i class="fas fa-chart-column"></i>
                                        <p>No best-selling products are available for the selected year yet.</p>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>

                        <div class="col-xl-6">
                            <section class="module-card module-table-card h-100">
                                <div class="module-table-head">
                                    <div>
                                        <h2>Low Selling Products</h2>
                                        <p class="module-card-subtitle mb-0">Products with the lowest movement, including items with no recorded sales.</p>
                                    </div>
                                </div>

                                <?php if (!empty($low_selling_rows)): ?>
                                    <div class="module-table-wrap">
                                        <div class="table-responsive">
                                            <table class="table table-hover module-table table-stack">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Units Sold</th>
                                                        <th>Revenue</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($low_selling_rows, 0, $display_limit) as $row): ?>
                                                        <tr>
                                                            <td data-label="Product">
                                                                <div class="product-name"><?= htmlspecialchars($row['product_name']) ?></div>
                                                                <div class="product-subline"><?= htmlspecialchars($row['category_name']) ?> &middot; <?= htmlspecialchars($row['brand_name']) ?></div>
                                                            </td>
                                                            <td data-label="Units Sold"><?= number_format((int) $row['units_sold']) ?></td>
                                                            <td data-label="Revenue"><?= htmlspecialchars(product_module_price_label($row['revenue'])) ?></td>
                                                            <td data-label="Status">
                                                                <span class="<?= (int) $row['units_sold'] > 0 ? 'status-pill status-low' : 'status-pill status-out' ?>">
                                                                    <?= (int) $row['units_sold'] > 0 ? 'Low Selling' : 'No Sales Yet' ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="module-empty">
                                        <i class="fas fa-box-open"></i>
                                        <p>No products are available in this shop yet.</p>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-xl-6">
                            <section class="module-card module-table-card h-100">
                                <div class="module-table-head">
                                    <div>
                                        <h2>Product Revenue Analysis</h2>
                                        <p class="module-card-subtitle mb-0">Products ranked by revenue contribution.</p>
                                    </div>
                                </div>

                                <?php if (!empty($revenue_rows)): ?>
                                    <div class="module-table-wrap">
                                        <div class="table-responsive">
                                            <table class="table table-hover module-table table-stack">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Revenue</th>
                                                        <th>Units</th>
                                                        <th>Avg / Unit</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($revenue_rows, 0, $display_limit) as $row): ?>
                                                        <?php $average_per_unit = (int) $row['units_sold'] > 0 ? ((float) $row['revenue'] / (int) $row['units_sold']) : 0; ?>
                                                        <tr>
                                                            <td data-label="Product">
                                                                <div class="product-name"><?= htmlspecialchars($row['product_name']) ?></div>
                                                                <div class="product-subline"><?= htmlspecialchars($row['category_name']) ?> &middot; <?= htmlspecialchars($row['brand_name']) ?></div>
                                                            </td>
                                                            <td data-label="Revenue"><?= htmlspecialchars(product_module_price_label($row['revenue'])) ?></td>
                                                            <td data-label="Units"><?= number_format((int) $row['units_sold']) ?></td>
                                                            <td data-label="Avg / Unit"><?= htmlspecialchars(product_module_price_label($average_per_unit)) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="module-empty">
                                        <i class="fas fa-sack-dollar"></i>
                                        <p>No product revenue data is available for the selected year.</p>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>

                        <div class="col-xl-6">
                            <section class="module-card module-table-card h-100">
                                <div class="module-table-head">
                                    <div>
                                        <h2>Product Order Count Analysis</h2>
                                        <p class="module-card-subtitle mb-0">Products ranked by how many different orders they appeared in.</p>
                                    </div>
                                </div>

                                <?php if (!empty($order_count_rows)): ?>
                                    <div class="module-table-wrap">
                                        <div class="table-responsive">
                                            <table class="table table-hover module-table table-stack">
                                                <thead>
                                                    <tr>
                                                        <th>Product</th>
                                                        <th>Orders</th>
                                                        <th>Units</th>
                                                        <th>Revenue</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($order_count_rows, 0, $display_limit) as $row): ?>
                                                        <tr>
                                                            <td data-label="Product">
                                                                <div class="product-name"><?= htmlspecialchars($row['product_name']) ?></div>
                                                                <div class="product-subline"><?= htmlspecialchars($row['category_name']) ?> &middot; <?= htmlspecialchars($row['brand_name']) ?></div>
                                                            </td>
                                                            <td data-label="Orders"><?= number_format((int) $row['order_count']) ?></td>
                                                            <td data-label="Units"><?= number_format((int) $row['units_sold']) ?></td>
                                                            <td data-label="Revenue"><?= htmlspecialchars(product_module_price_label($row['revenue'])) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="module-empty">
                                        <i class="fas fa-receipt"></i>
                                        <p>No product order history is available for the selected year.</p>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>

                    <section class="module-card module-table-card">
                        <div class="module-table-head">
                            <div>
                                <h2>Product Performance Ranking</h2>
                                <p class="module-card-subtitle mb-0">Composite score weighted toward revenue, units sold, and order count for balanced product ranking.</p>
                            </div>
                        </div>

                        <?php if (!empty($ranking_rows)): ?>
                            <div class="module-table-wrap">
                                <div class="table-responsive">
                                    <table class="table table-hover module-table table-stack">
                                        <thead>
                                            <tr>
                                                <th>Rank</th>
                                                <th>Product</th>
                                                <th>Score</th>
                                                <th>Revenue</th>
                                                <th>Units</th>
                                                <th>Orders</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($ranking_rows, 0, $display_limit) as $row): ?>
                                                <?php
                                                $rank_class = 'rank-chip rank-chip-standard';
                                                if ((int) $row['rank'] === 1) {
                                                    $rank_class = 'rank-chip rank-chip-gold';
                                                } elseif ((int) $row['rank'] === 2) {
                                                    $rank_class = 'rank-chip rank-chip-silver';
                                                } elseif ((int) $row['rank'] === 3) {
                                                    $rank_class = 'rank-chip rank-chip-bronze';
                                                }
                                                ?>
                                                <tr>
                                                    <td data-label="Rank"><span class="<?= htmlspecialchars($rank_class) ?>">#<?= number_format((int) $row['rank']) ?></span></td>
                                                    <td data-label="Product">
                                                        <div class="product-name"><?= htmlspecialchars($row['product_name']) ?></div>
                                                        <div class="product-subline"><?= htmlspecialchars($row['category_name']) ?> &middot; <?= htmlspecialchars($row['brand_name']) ?></div>
                                                    </td>
                                                    <td data-label="Score"><span class="performance-score"><?= number_format((float) $row['performance_score'], 1) ?></span></td>
                                                    <td data-label="Revenue"><?= htmlspecialchars(product_module_price_label($row['revenue'])) ?></td>
                                                    <td data-label="Units"><?= number_format((int) $row['units_sold']) ?></td>
                                                    <td data-label="Orders"><?= number_format((int) $row['order_count']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="module-empty">
                                <i class="fas fa-ranking-star"></i>
                                <p>No catalog products are available to rank yet.</p>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="module-card module-table-card">
                        <div class="module-table-head">
                            <div>
                                <h2>Monthly Product Sales Report</h2>
                                <p class="module-card-subtitle mb-0">Month-by-month order count, product movement, revenue, and the top-performing product for <?= (int) $selected_year ?>.</p>
                            </div>
                        </div>

                        <div class="module-table-wrap">
                            <div class="table-responsive">
                                <table class="table table-hover module-table table-stack">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th>Orders</th>
                                            <th>Products Sold</th>
                                            <th>Units Sold</th>
                                            <th>Revenue</th>
                                            <th>Top Product</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_rows as $row): ?>
                                            <tr>
                                                <td data-label="Month">
                                                    <div class="product-name"><?= htmlspecialchars($row['month_label']) ?></div>
                                                    <div class="product-subline"><?= htmlspecialchars($row['month_key']) ?></div>
                                                </td>
                                                <td data-label="Orders"><?= number_format((int) $row['order_count']) ?></td>
                                                <td data-label="Products Sold"><?= number_format((int) $row['product_count']) ?></td>
                                                <td data-label="Units Sold"><?= number_format((int) $row['units_sold']) ?></td>
                                                <td data-label="Revenue"><?= htmlspecialchars(product_module_price_label($row['revenue'])) ?></td>
                                                <td data-label="Top Product">
                                                    <div class="product-name"><?= htmlspecialchars($row['leader_name']) ?></div>
                                                    <div class="product-subline">
                                                        <?= (int) $row['leader_units_sold'] > 0 ? number_format((int) $row['leader_units_sold']) . ' units' : 'No product sales recorded' ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/product-management.js"></script>
    <script>
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.08)';
        Chart.defaults.font.family = "'Inter', sans-serif";

        function createPerformanceChart(elementId, type, labels, datasets, options) {
            var element = document.getElementById(elementId);
            if (!element) {
                return null;
            }

            return new Chart(element.getContext('2d'), {
                type: type,
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: Object.assign({
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }, options || {})
            });
        }

        createPerformanceChart('topSellingProductsChart', 'bar', <?= json_encode($top_selling_chart_labels) ?>, [{
            label: 'Units Sold',
            data: <?= json_encode($top_selling_chart_values) ?>,
            backgroundColor: 'rgba(59, 130, 246, 0.62)',
            borderColor: '#60a5fa',
            borderWidth: 1.5,
            borderRadius: 10
        }], {
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        });

        createPerformanceChart('productRevenueChart', 'bar', <?= json_encode($revenue_chart_labels) ?>, [{
            label: 'Revenue (LKR)',
            data: <?= json_encode($revenue_chart_values) ?>,
            backgroundColor: 'rgba(16, 185, 129, 0.62)',
            borderColor: '#34d399',
            borderWidth: 1.5,
            borderRadius: 10
        }], {
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        });
    </script>
</body>

</html>
