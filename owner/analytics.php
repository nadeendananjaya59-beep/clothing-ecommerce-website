<?php

/**
 * Analytics & Dashboard Charts Module - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// ─── Query 1 & 2: Monthly Sales & Monthly Revenue (Last 6 Months) ───
$monthly_data_sql = "SELECT DATE_FORMAT(order_date, '%b %Y') AS month_name, 
                            DATE_FORMAT(order_date, '%Y-%m') AS month_key, 
                            COUNT(order_id) AS total_orders, 
                            SUM(total_amount) AS total_revenue 
                     FROM orders 
                     WHERE shop_id = $shop_id AND order_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                     GROUP BY month_key 
                     ORDER BY month_key ASC";
$monthly_res = mysqli_query($conn, $monthly_data_sql);
$months = [];
$sales_counts = [];
$revenues = [];
if ($monthly_res) {
    while ($row = mysqli_fetch_assoc($monthly_res)) {
        $months[]       = $row['month_name'];
        $sales_counts[] = (int)$row['total_orders'];
        $revenues[]     = (float)$row['total_revenue'];
    }
}

// ─── Query 3: Order Status Pie Chart ──────────────────────────────
$status_sql = "SELECT order_status, COUNT(*) AS status_count 
               FROM orders 
               WHERE shop_id = $shop_id 
               GROUP BY order_status";
$status_res = mysqli_query($conn, $status_sql);
$status_labels = [];
$status_vals = [];
if ($status_res) {
    while ($row = mysqli_fetch_assoc($status_res)) {
        $status_labels[] = $row['order_status'];
        $status_vals[]   = (int)$row['status_count'];
    }
}

// ─── Query 4: Payment Method Pie Chart ────────────────────────────
$pay_sql = "SELECT pm.method_name, COUNT(p.payment_id) AS payment_count 
            FROM payments p 
            JOIN orders o ON p.order_id = o.order_id 
            JOIN payment_methods pm ON p.method_id = pm.method_id 
            WHERE o.shop_id = $shop_id AND p.payment_status = 'Completed'
            GROUP BY pm.method_name";
$pay_res = mysqli_query($conn, $pay_sql);
$pay_labels = [];
$pay_vals = [];
if ($pay_res) {
    while ($row = mysqli_fetch_assoc($pay_res)) {
        $pay_labels[] = $row['method_name'];
        $pay_vals[]   = (int)$row['payment_count'];
    }
}

// ─── Query 5: Top 10 Selling Products Chart ───────────────────────
$prod_sql = "SELECT p.product_name, SUM(oi.quantity) AS units_sold 
             FROM order_items oi 
             JOIN products p ON oi.product_id = p.product_id 
             JOIN orders o ON oi.order_id = o.order_id 
             WHERE p.shop_id = $shop_id AND o.order_status != 'Cancelled'
             GROUP BY p.product_id 
             ORDER BY units_sold DESC 
             LIMIT 10";
$prod_res = mysqli_query($conn, $prod_sql);
$prod_labels = [];
$prod_vals = [];
if ($prod_res) {
    while ($row = mysqli_fetch_assoc($prod_res)) {
        $prod_labels[] = strlen($row['product_name']) > 20 ? substr($row['product_name'], 0, 17) . '...' : $row['product_name'];
        $prod_vals[]   = (int)$row['units_sold'];
    }
}

// ─── Query 6: Customer Growth Chart (Last 6 Months) ───────────────
$growth_sql = "SELECT DATE_FORMAT(min_date, '%b %Y') AS month_name,
                      DATE_FORMAT(min_date, '%Y-%m') AS month_key,
                      COUNT(customer_id) AS new_customers
               FROM (
                   SELECT customer_id, MIN(order_date) AS min_date
                   FROM orders
                   WHERE shop_id = $shop_id
                   GROUP BY customer_id
               ) t
               WHERE min_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
               GROUP BY month_key
               ORDER BY month_key ASC";
$growth_res = mysqli_query($conn, $growth_sql);
$growth_months = [];
$growth_vals = [];
if ($growth_res) {
    $cumulative = 0;
    while ($row = mysqli_fetch_assoc($growth_res)) {
        $cumulative += (int)$row['new_customers'];
        $growth_months[] = $row['month_name'];
        $growth_vals[]   = $cumulative;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Analytics & Charts | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media(max-width: 992px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-box {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            min-height: 380px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all var(--transition);
        }

        .chart-box:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }

        .chart-box-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            padding-bottom: 0.6rem;
        }

        .chart-box-header h2 {
            font-size: 0.95rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .chart-container {
            position: relative;
            flex: 1;
            width: 100%;
            height: 280px;
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
                    <h1>📊 Business Analytics & Charts</h1>
                    <p>Analyze sales metrics, revenue streams, order status trends, and shopper dynamics using interactive models.</p>
                </div>

                <!-- ══ Charts Section Grid ══ -->
                <div class="charts-grid">

                    <!-- 1. Monthly Sales Chart -->
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <i class="fas fa-chart-line" style="color:#6c63ff;"></i>
                            <h2>Monthly Sales Volume (Order Count)</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="monthlySalesChart"></canvas>
                        </div>
                    </div>

                    <!-- 2. Monthly Revenue Chart -->
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <i class="fas fa-sack-dollar" style="color:#10b981;"></i>
                            <h2>Monthly Revenue Stream (LKR)</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="monthlyRevenueChart"></canvas>
                        </div>
                    </div>

                    <!-- 3. Order Status Pie Chart -->
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <i class="fas fa-chart-pie" style="color:#fbbf24;"></i>
                            <h2>Order Fulfillment Distribution</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="orderStatusChart"></canvas>
                        </div>
                    </div>

                    <!-- 4. Payment Method Pie Chart -->
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <i class="fas fa-credit-card" style="color:#3b82f6;"></i>
                            <h2>Successful Payment Channel Breakdown</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="paymentMethodChart"></canvas>
                        </div>
                    </div>

                    <!-- 5. Top 10 Selling Products Chart -->
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <i class="fas fa-fire" style="color:#ef4444;"></i>
                            <h2>Top 10 Selling Products (Units Sold)</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="topProductsChart"></canvas>
                        </div>
                    </div>

                    <!-- 6. Customer Growth Chart -->
                    <div class="chart-box">
                        <div class="chart-box-header">
                            <i class="fas fa-chart-area" style="color:#06b6d4;"></i>
                            <h2>Acquisition & Cumulative Customer Growth</h2>
                        </div>
                        <div class="chart-container">
                            <canvas id="customerGrowthChart"></canvas>
                        </div>
                    </div>

                </div>

                <!-- Back to Dashboard Button -->
                <div style="margin-top: 2rem;">
                    <a href="dashboard.php" class="btn-reset" style="display:inline-flex;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>

            </div>
        </div>

        <script>
            // Chart.js Theme Configurations
            Chart.defaults.color = '#94a3b8';
            Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.08)';
            Chart.defaults.font.family = "'Inter', sans-serif";

            // Helper to draw charts with consistent styling
            const createChart = (id, type, labels, datasets, options = {}) => {
                const ctx = document.getElementById(id).getContext('2d');
                return new Chart(ctx, {
                    type: type,
                    data: {
                        labels: labels,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: type === 'pie' || type === 'doughnut',
                                position: 'bottom',
                                labels: {
                                    boxWidth: 12,
                                    padding: 15
                                }
                            }
                        },
                        ...options
                    }
                });
            };

            // 1. Monthly Sales Chart (Line Chart)
            createChart('monthlySalesChart', 'line', <?= json_encode($months) ?>, [{
                label: 'Orders Placed',
                data: <?= json_encode($sales_counts) ?>,
                borderColor: '#6c63ff',
                backgroundColor: 'rgba(108, 99, 255, 0.1)',
                borderWidth: 2,
                tension: 0.35,
                fill: true
            }], {
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            });

            // 2. Monthly Revenue Chart (Bar Chart)
            createChart('monthlyRevenueChart', 'bar', <?= json_encode($months) ?>, [{
                label: 'Revenue (LKR)',
                data: <?= json_encode($revenues) ?>,
                backgroundColor: 'rgba(16, 185, 129, 0.65)',
                borderColor: '#10b981',
                borderWidth: 1.5,
                borderRadius: 4
            }]);

            // 3. Order Status Chart (Doughnut)
            createChart('orderStatusChart', 'doughnut', <?= json_encode($status_labels) ?>, [{
                data: <?= json_encode($status_vals) ?>,
                backgroundColor: ['#fbbf24', '#3b82f6', '#8b5cf6', '#10b981', '#ef4444'],
                borderWidth: 0
            }]);

            // 4. Payment Method Chart (Pie)
            createChart('paymentMethodChart', 'pie', <?= json_encode($pay_labels) ?>, [{
                data: <?= json_encode($pay_vals) ?>,
                backgroundColor: ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899'],
                borderWidth: 0
            }]);

            // 5. Top 10 Selling Products Chart (Horizontal Bar Chart)
            createChart('topProductsChart', 'bar', <?= json_encode($prod_labels) ?>, [{
                label: 'Units Sold',
                data: <?= json_encode($prod_vals) ?>,
                backgroundColor: 'rgba(239, 68, 68, 0.65)',
                borderColor: '#ef4444',
                borderWidth: 1.5,
                borderRadius: 4
            }], {
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            });

            // 6. Customer Growth Chart (Area Chart)
            createChart('customerGrowthChart', 'line', <?= json_encode($growth_months) ?>, [{
                label: 'Cumulative Customer Base',
                data: <?= json_encode($growth_vals) ?>,
                borderColor: '#06b6d4',
                backgroundColor: 'rgba(6, 182, 212, 0.1)',
                borderWidth: 2,
                tension: 0.3,
                fill: true
            }], {
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            });
        </script>
</body>

</html>