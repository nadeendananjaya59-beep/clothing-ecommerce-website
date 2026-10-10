<?php

/**
 * Inventory Management Module - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// ─── Fetch Categories & Brands for Filters ────────────────
$categories_res = mysqli_query($conn, "SELECT category_id, category_name FROM categories WHERE shop_id = $shop_id OR shop_id IS NULL ORDER BY category_name ASC");
$brands_res     = mysqli_query($conn, "SELECT brand_id, brand_name FROM brands WHERE shop_id = $shop_id OR shop_id IS NULL ORDER BY brand_name ASC");

$categories = [];
if ($categories_res) {
    while ($row = mysqli_fetch_assoc($categories_res)) {
        $categories[] = $row;
    }
}
$brands = [];
if ($brands_res) {
    while ($row = mysqli_fetch_assoc($brands_res)) {
        $brands[] = $row;
    }
}

// ─── Summary Stats (Filtered by Shop ID) ──────────────────
// 1. Total Stock Quantity
$r = mysqli_query($conn, "SELECT COALESCE(SUM(stock_qty), 0) AS total_stock FROM products WHERE shop_id = $shop_id");
$total_stock = mysqli_fetch_assoc($r)['total_stock'];

// 2. Low Stock Products (stock_qty <= reorder_level AND stock_qty > 0)
$r = mysqli_query($conn, "SELECT COUNT(*) AS low_count FROM products WHERE shop_id = $shop_id AND stock_qty > 0 AND stock_qty <= reorder_level");
$low_stock_count = mysqli_fetch_assoc($r)['low_count'];

// 3. Out of Stock Products (stock_qty = 0)
$r = mysqli_query($conn, "SELECT COUNT(*) AS out_count FROM products WHERE shop_id = $shop_id AND (stock_qty = 0 OR status = 'Out of Stock')");
$out_stock_count = mysqli_fetch_assoc($r)['out_count'];

// ─── Filters ─────────────────────────────────────────────
$f_search   = trim($_GET['search'] ?? '');
$f_category = trim($_GET['category'] ?? '');
$f_brand    = trim($_GET['brand'] ?? '');
$f_alert    = trim($_GET['alert'] ?? ''); // 'low' or 'out'

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset   = ($page - 1) * $per_page;

// Build WHERE clause
$where = ["p.shop_id = $shop_id"];

if ($f_search !== '') {
    $search_esc = mysqli_real_escape_string($conn, $f_search);
    $where[] = "(p.product_name LIKE '%$search_esc%' OR p.description LIKE '%$search_esc%')";
}
if ($f_category !== '') {
    $cat_esc = (int)$f_category;
    $where[] = "p.category_id = $cat_esc";
}
if ($f_brand !== '') {
    $brand_esc = (int)$f_brand;
    $where[] = "p.brand_id = $brand_esc";
}
if ($f_alert === 'low') {
    $where[] = "p.stock_qty > 0 AND p.stock_qty <= p.reorder_level";
} elseif ($f_alert === 'out') {
    $where[] = "(p.stock_qty = 0 OR p.status = 'Out of Stock')";
}

$where_sql = implode(' AND ', $where);

// Count total matching products
$count_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM products p WHERE $where_sql");
$total_rows = mysqli_fetch_assoc($count_res)['c'];
$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch main product list
$products_sql = "SELECT p.*, c.category_name, b.brand_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.category_id 
                 LEFT JOIN brands b ON p.brand_id = b.brand_id 
                 WHERE $where_sql 
                 ORDER BY p.stock_qty ASC, p.product_name ASC 
                 LIMIT $per_page OFFSET $offset";
$products_res = mysqli_query($conn, $products_sql);
$products = [];
if ($products_res) {
    while ($row = mysqli_fetch_assoc($products_res)) {
        $products[] = $row;
    }
}

// ─── 4. Most Sold Products (Filtered by Shop ID) ──────────
$most_sold_sql = "SELECT p.product_name, SUM(oi.quantity) AS units_sold, SUM(oi.subtotal) AS total_revenue, p.stock_qty
                  FROM order_items oi
                  JOIN products p ON oi.product_id = p.product_id
                  JOIN orders o ON oi.order_id = o.order_id
                  WHERE p.shop_id = $shop_id AND o.order_status != 'Cancelled'
                  GROUP BY p.product_id
                  ORDER BY units_sold DESC
                  LIMIT 5";
$most_sold_res = mysqli_query($conn, $most_sold_sql);
$most_sold = [];
if ($most_sold_res) {
    while ($row = mysqli_fetch_assoc($most_sold_res)) {
        $most_sold[] = $row;
    }
}

// ─── 5. Recently Added Products (Filtered by Shop ID) ─────
$recent_products_sql = "SELECT p.product_name, p.selling_price, p.stock_qty, p.created_at, c.category_name
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        WHERE p.shop_id = $shop_id
                        ORDER BY p.created_at DESC
                        LIMIT 5";
$recent_products_res = mysqli_query($conn, $recent_products_sql);
$recent_products = [];
if ($recent_products_res) {
    while ($row = mysqli_fetch_assoc($recent_products_res)) {
        $recent_products[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        /* ── Summary Stats ── */
        .inv-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media(max-width: 768px) {
            .inv-grid {
                grid-template-columns: 1fr;
            }
        }

        .inv-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: all var(--transition);
        }

        .inv-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }

        .inv-card::before {
            content: '';
            position: absolute;
            top: -25px;
            right: -15px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            opacity: .08;
        }

        .ic-purple {
            border-top: 3px solid #6c63ff;
        }

        .ic-purple::before {
            background: #6c63ff;
        }

        .ic-purple .ic-icon {
            background: rgba(108, 99, 255, .15);
            color: #8b84ff;
        }

        .ic-amber {
            border-top: 3px solid #f59e0b;
        }

        .ic-amber::before {
            background: #f59e0b;
        }

        .ic-amber .ic-icon {
            background: rgba(245, 158, 11, .15);
            color: #fbbf24;
        }

        .ic-red {
            border-top: 3px solid #ef4444;
        }

        .ic-red::before {
            background: #ef4444;
        }

        .ic-red .ic-icon {
            background: rgba(239, 68, 68, .15);
            color: #f87171;
        }

        .ic-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }

        .ic-sub {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: .2rem;
        }

        .ic-val {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }

        /* Filter block */
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

        /* Tables & Lists */
        .product-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .product-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }

        .product-card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
        }

        .product-table th {
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

        .product-table td {
            padding: .9rem 1rem;
            font-size: .86rem;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(255, 255, 255, .04);
            vertical-align: middle;
        }

        .product-table tr:last-child td {
            border-bottom: none;
        }

        .product-table tbody tr:hover td {
            background: rgba(108, 99, 255, .04);
        }

        /* Progress bars */
        .stock-progress-container {
            display: flex;
            align-items: center;
            gap: .6rem;
            width: 120px;
        }

        .stock-progress-bar {
            flex: 1;
            background: var(--border-light);
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
        }

        .stock-progress-fill {
            height: 100%;
            border-radius: 3px;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .badge-instock {
            background: rgba(16, 185, 129, .12);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .badge-lowstock {
            background: rgba(245, 158, 11, .12);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, .25);
        }

        .badge-outstock {
            background: rgba(239, 68, 68, .12);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, .25);
        }

        /* Two columns lists */
        .lists-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media(max-width: 900px) {
            .lists-row {
                grid-template-columns: 1fr;
            }
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

                <!-- Header -->
                <div class="dashboard-header">
                    <h1>📦 Inventory Management</h1>
                    <p>Monitor stock quantities, reorder points, low/out of stock statuses, and hot products.</p>
                </div>

                <!-- ══ Inv Summary Stats ══ -->
                <div class="inv-grid">
                    <div class="inv-card ic-purple">
                        <div class="ic-icon"><i class="fas fa-cubes"></i></div>
                        <div class="ic-sub">Total Stock Quantity</div>
                        <div class="ic-val"><?= number_format($total_stock) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">units</span></div>
                    </div>
                    <div class="inv-card ic-amber">
                        <div class="ic-icon"><i class="fas fa-triangle-exclamation"></i></div>
                        <div class="ic-sub">Low Stock Products</div>
                        <div class="ic-val"><?= number_format($low_stock_count) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">items</span></div>
                    </div>
                    <div class="inv-card ic-red">
                        <div class="ic-icon"><i class="fas fa-ban"></i></div>
                        <div class="ic-sub">Out of Stock Products</div>
                        <div class="ic-val"><?= number_format($out_stock_count) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">items</span></div>
                    </div>
                </div>

                <!-- ══ Filters ══ -->
                <div class="filter-card">
                    <form method="GET" action="inventory.php">
                        <div class="filter-row">
                            <div class="filter-group" style="flex:2; min-width:200px;">
                                <label><i class="fas fa-magnifying-glass"></i> Search Products</label>
                                <input type="text" name="search" placeholder="Search by name, description..." value="<?= htmlspecialchars($f_search) ?>">
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-folder"></i> Category</label>
                                <select name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['category_id'] ?>" <?= $f_category == $cat['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-copyright"></i> Brand</label>
                                <select name="brand">
                                    <option value="">All Brands</option>
                                    <?php foreach ($brands as $br): ?>
                                        <option value="<?= $br['brand_id'] ?>" <?= $f_brand == $br['brand_id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['brand_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-bell"></i> Stock Alert Filter</label>
                                <select name="alert">
                                    <option value="">No Filter</option>
                                    <option value="low" <?= $f_alert === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
                                    <option value="out" <?= $f_alert === 'out' ? 'selected' : '' ?>>Out of Stock Only</option>
                                </select>
                            </div>

                            <div style="display:flex; gap:.5rem;">
                                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
                                <a href="inventory.php" class="btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ══ Main Products Table ══ -->
                <div class="product-card">
                    <div class="product-card-header">
                        <h2>Product Catalog & Stock Alert List</h2>
                        <span class="count-badge" style="background:rgba(108,99,255,.15); color:var(--primary-light); padding:.2rem .65rem; border-radius:20px; font-size:.75rem; font-weight:700; border:1px solid rgba(108,99,255,.25);"><?= number_format($total_rows) ?> Product(s)</span>
                    </div>

                    <?php if (!empty($products)): ?>
                        <div style="overflow-x:auto;">
                            <table class="product-table">
                                <thead>
                                    <tr>
                                        <th>Product ID</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Brand</th>
                                        <th style="text-align:right;">Selling Price</th>
                                        <th style="text-align:center;">Stock Qty</th>
                                        <th style="text-align:center;">Reorder Level</th>
                                        <th>Stock Status</th>
                                        <th>Progress Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $p): ?>
                                        <?php
                                        $qty     = $p['stock_qty'];
                                        $reorder = $p['reorder_level'];

                                        // Determine Stock Status
                                        if ($qty == 0 || $p['status'] == 'Out of Stock') {
                                            $status_badge = '<span class="badge badge-outstock">Out of Stock</span>';
                                            $pct = 0;
                                            $bar_color = '#ef4444';
                                        } elseif ($qty <= $reorder) {
                                            $status_badge = '<span class="badge badge-lowstock">Low Stock</span>';
                                            $pct = $reorder > 0 ? min(100, round(($qty / $reorder) * 100)) : 100;
                                            $bar_color = '#f59e0b';
                                        } else {
                                            $status_badge = '<span class="badge badge-instock">In Stock</span>';
                                            $pct = 100;
                                            $bar_color = '#10b981';
                                        }
                                        ?>
                                        <tr>
                                            <td style="color:var(--primary-light); font-weight:700;">#<?= $p['product_id'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($p['product_name']) ?></strong>
                                            </td>
                                            <td><?= htmlspecialchars($p['category_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($p['brand_name'] ?? '—') ?></td>
                                            <td style="text-align:right; font-weight:600;">LKR <?= number_format($p['selling_price'], 2) ?></td>
                                            <td style="text-align:center; font-weight:700; color:<?= $bar_color ?>;"><?= $qty ?></td>
                                            <td style="text-align:center; color:var(--text-muted);"><?= $reorder ?></td>
                                            <td><?= $status_badge ?></td>
                                            <td>
                                                <div class="stock-progress-container">
                                                    <div class="stock-progress-bar">
                                                        <div class="stock-progress-fill" style="width: <?= $pct ?>%; background: <?= $bar_color ?>;"></div>
                                                    </div>
                                                    <span style="font-size:0.75rem; color:var(--text-secondary);"><?= $pct ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $params = array_filter(['search' => $f_search, 'category' => $f_category, 'brand' => $f_brand, 'alert' => $f_alert]);
                            $query_string = http_build_query($params);
                            $base_url = 'inventory.php?' . ($query_string ? $query_string . '&' : '');
                            ?>
                            <div class="pagination">
                                <a class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                    <a class="pg-btn <?= $p === $page ? 'active' : '' ?>" href="<?= $base_url ?>page=<?= $p ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <a class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                                <span class="pg-info">Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_rows) ?> products</span>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div style="text-align:center; padding:3rem 2rem;">
                            <i class="fas fa-box-open" style="font-size:3rem; color:var(--text-muted); margin-bottom:1rem; display:block;"></i>
                            <p style="color:var(--text-muted); margin:0;">No products match your filter parameters.</p>
                            <a href="inventory.php" class="btn-reset" style="display:inline-flex; margin-top:1rem;"><i class="fas fa-rotate-left"></i> Clear Filters</a>
                        </div>
                    <?php endif; ?>

                </div><!-- /dashboard-main -->
            </div><!-- /dashboard-container -->
        </div><!-- /dashboard-wrapper -->

</body>

</html>
</div>

<!-- ══ Lists (Most Sold & Recently Added) ══ -->
<div class="lists-row">
    <!-- Most Sold Products -->
    <div class="info-card" style="margin:0;">
        <div class="info-card-header">
            <i class="fas fa-fire" style="color:#ef4444;"></i>
            <h2>Most Sold Products</h2>
        </div>
        <?php if (!empty($most_sold)): ?>
            <table class="product-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th style="text-align:center;">Units Sold</th>
                        <th style="text-align:right;">Total Sales</th>
                        <th style="text-align:center;">Current Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($most_sold as $ms): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($ms['product_name']) ?></strong></td>
                            <td style="text-align:center; font-weight:700; color:var(--primary-light);"><?= number_format($ms['units_sold']) ?></td>
                            <td style="text-align:right; font-weight:600;">LKR <?= number_format($ms['total_revenue'], 2) ?></td>
                            <td style="text-align:center; color:var(--text-secondary);"><?= $ms['stock_qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted); padding:1rem; text-align:center;">No sales records found yet.</p>
        <?php endif; ?>
    </div>

    <!-- Recently Added Products -->
    <div class="info-card" style="margin:0;">
        <div class="info-card-header">
            <i class="fas fa-calendar-plus" style="color:var(--primary-light);"></i>
            <h2>Recently Added Products</h2>
        </div>
        <?php if (!empty($recent_products)): ?>
            <table class="product-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th style="text-align:right;">Price</th>
                        <th style="text-align:center;">Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_products as $rp): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($rp['product_name']) ?></strong></td>
                            <td><?= htmlspecialchars($rp['category_name'] ?? '—') ?></td>
                            <td style="text-align:right; font-weight:600;">LKR <?= number_format($rp['selling_price'], 2) ?></td>
                            <td style="text-align:center; color:var(--text-secondary);"><?= $rp['stock_qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted); padding:1rem; text-align:center;">No recently added products.</p>
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
    // Auto-submit selectors on change
    document.querySelectorAll('.filter-group select').forEach(function(select) {
        select.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });
</script>
</body>

</html>