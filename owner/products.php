<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_helpers.php';

require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id = (int) ($_SESSION['owner_shop_id'] ?? 0);
$initials = product_module_owner_initials($owner_name);
$flash = get_flash();
$schema_issues = $shop_id > 0 ? product_module_schema_issues($conn) : ['Your account is not linked to a shop yet.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security verification failed. Please try again.');
        header('Location: products.php');
        exit();
    }

    if (!empty($schema_issues)) {
        set_flash('danger', 'The product module cannot run until the shop schema patch has been applied.');
        header('Location: products.php');
        exit();
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'delete_product') {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        $delete_result = product_module_delete_product($conn, $product_id, $shop_id);
        set_flash($delete_result['success'] ? 'success' : 'danger', $delete_result['message']);
        header('Location: products.php');
        exit();
    }
}

$filter_search = trim((string) ($_GET['search'] ?? ''));
$filter_category = isset($_GET['category']) && ctype_digit((string) $_GET['category']) ? (string) ((int) $_GET['category']) : '';
$filter_brand = isset($_GET['brand']) && ctype_digit((string) $_GET['brand']) ? (string) ((int) $_GET['brand']) : '';
$allowed_statuses = ['Available', 'Out of Stock'];
$filter_status = in_array(($_GET['status'] ?? ''), $allowed_statuses, true) ? (string) $_GET['status'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;

$category_options = [];
$brand_options = [];
$summary = [
    'total_products' => 0,
    'available_products' => 0,
    'low_stock_products' => 0,
    'out_of_stock_products' => 0
];
$products = [];
$total_rows = 0;
$total_pages = 1;

if (empty($schema_issues)) {
    $category_options = product_module_fetch_lookup_options($conn, 'categories', $shop_id);
    $brand_options = product_module_fetch_lookup_options($conn, 'brands', $shop_id);

    $summary_stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT COUNT(*) AS total_products,
                SUM(CASE WHEN status = 'Available' AND stock_qty > 0 THEN 1 ELSE 0 END) AS available_products,
                SUM(CASE WHEN stock_qty > 0 AND stock_qty <= reorder_level THEN 1 ELSE 0 END) AS low_stock_products,
                SUM(CASE WHEN status = 'Out of Stock' OR stock_qty <= 0 THEN 1 ELSE 0 END) AS out_of_stock_products
         FROM products
         WHERE shop_id = ?",
        [(string) $shop_id]
    );

    if ($summary_stmt) {
        $summary_result = mysqli_stmt_get_result($summary_stmt);
        $summary_row = $summary_result ? mysqli_fetch_assoc($summary_result) : null;
        if ($summary_row) {
            $summary = array_merge($summary, $summary_row);
        }
        mysqli_stmt_close($summary_stmt);
    }

    $joins = " FROM products p
               LEFT JOIN categories c ON p.category_id = c.category_id
               LEFT JOIN brands b ON p.brand_id = b.brand_id
               LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id";

    $where = ["p.shop_id = ?"];
    $params = [(string) $shop_id];

    if ($filter_search !== '') {
        $where[] = "(p.product_name LIKE ? OR p.description LIKE ? OR c.category_name LIKE ? OR b.brand_name LIKE ? OR s.supplier_name LIKE ?)";
        $like = '%' . $filter_search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($filter_category !== '') {
        $where[] = "p.category_id = ?";
        $params[] = $filter_category;
    }

    if ($filter_brand !== '') {
        $where[] = "p.brand_id = ?";
        $params[] = $filter_brand;
    }

    if ($filter_status !== '') {
        $where[] = "p.status = ?";
        $params[] = $filter_status;
    }

    $where_sql = implode(' AND ', $where);

    $count_stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT COUNT(*) AS total_products" . $joins . " WHERE $where_sql",
        $params
    );

    if ($count_stmt) {
        $count_result = mysqli_stmt_get_result($count_stmt);
        $count_row = $count_result ? mysqli_fetch_assoc($count_result) : null;
        $total_rows = (int) ($count_row['total_products'] ?? 0);
        mysqli_stmt_close($count_stmt);
    }

    $total_pages = max(1, (int) ceil($total_rows / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }

    $offset = ($page - 1) * $per_page;
    $list_sql = "SELECT p.*,
                        c.category_name,
                        b.brand_name,
                        s.supplier_name,
                        (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.product_id) AS image_count,
                        (SELECT COUNT(*) FROM product_variations pv WHERE pv.product_id = p.product_id) AS variation_count" .
                $joins .
                " WHERE $where_sql
                  ORDER BY p.created_at DESC, p.product_name ASC
                  LIMIT $per_page OFFSET $offset";

    $list_stmt = product_module_prepare_and_execute($conn, $list_sql, $params);
    if ($list_stmt) {
        $list_result = mysqli_stmt_get_result($list_stmt);
        $products = product_module_fetch_all($list_result);
        mysqli_stmt_close($list_stmt);
    }
}

$query_params = $_GET;
unset($query_params['page']);
$base_query = http_build_query($query_params);
$page_url_prefix = 'products.php' . ($base_query !== '' ? '?' . $base_query . '&' : '?');
$display_start = $total_rows > 0 ? (($page - 1) * $per_page) + 1 : 0;
$display_end = min($total_rows, $page * $per_page);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <link rel="stylesheet" href="assets/css/product-management.css">
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
                    <h1>Product Management</h1>
                    <p>Manage your catalog, prices, stock levels, and product visibility from one place.</p>
                </div>

                <section class="module-hero">
                    <div>
                        <h2>Catalog operations for shop #<?= (int) $shop_id ?></h2>
                        <p>Add new products, refine pricing, keep stock healthy, and search the catalog with shop-aware filtering.</p>
                    </div>
                    <div class="module-hero-actions">
                        <span class="module-pill"><i class="fas fa-filter"></i> Session-scoped data</span>
                        <a href="inventory.php" class="btn btn-outline-light">Inventory View</a>
                        <a href="product_codes.php" class="btn btn-outline-light">Codes & Labels</a>
                        <a href="product_performance.php" class="btn btn-outline-light">Performance Analytics</a>
                        <a href="product_form.php" class="btn btn-light module-accent-btn">
                            <i class="fas fa-plus"></i> Add Product
                        </a>
                    </div>
                </section>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> module-alert" role="alert">
                        <?= htmlspecialchars($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($schema_issues)): ?>
                    <div class="alert alert-warning module-alert" role="alert">
                        <div class="d-flex align-items-start gap-3">
                            <i class="fas fa-triangle-exclamation mt-1"></i>
                            <div>
                                <strong>Schema setup required.</strong>
                                <p class="mb-2">The product module needs shop-aware columns before it can run.</p>
                                <ul class="mb-2">
                                    <?php foreach ($schema_issues as $issue): ?>
                                        <li><?= htmlspecialchars($issue) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="mb-0">Apply the patch in <code>owner/db/product_management_patch.sql</code> or run <code>owner/db/update_schema.php</code>.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <section class="module-stats">
                        <article class="module-stat stat-primary">
                            <div class="module-stat-icon"><i class="fas fa-boxes-stacked"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $summary['total_products']) ?></div>
                            <div class="module-stat-label">Total Products</div>
                        </article>
                        <article class="module-stat stat-success">
                            <div class="module-stat-icon"><i class="fas fa-circle-check"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $summary['available_products']) ?></div>
                            <div class="module-stat-label">Available</div>
                        </article>
                        <article class="module-stat stat-warning">
                            <div class="module-stat-icon"><i class="fas fa-bell"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $summary['low_stock_products']) ?></div>
                            <div class="module-stat-label">Low Stock</div>
                        </article>
                        <article class="module-stat stat-danger">
                            <div class="module-stat-icon"><i class="fas fa-box-open"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $summary['out_of_stock_products']) ?></div>
                            <div class="module-stat-label">Out of Stock</div>
                        </article>
                    </section>

                    <section class="module-card">
                        <div class="module-card-header">
                            <div>
                                <h2>Search and Filter</h2>
                                <p class="module-card-subtitle">Narrow the catalog by product text, category, brand, or live status.</p>
                            </div>
                        </div>

                        <form method="GET" action="products.php" id="productFilterForm">
                            <div class="module-filter-grid">
                                <div>
                                    <label class="form-label" for="searchInput">Search Products</label>
                                    <input type="text" class="form-control" id="searchInput" name="search" placeholder="Name, description, category, brand, supplier..." value="<?= htmlspecialchars($filter_search) ?>">
                                </div>
                                <div>
                                    <label class="form-label" for="categoryFilter">Category</label>
                                    <select class="form-select" id="categoryFilter" name="category" data-submit-on-change="true">
                                        <option value="">All Categories</option>
                                        <?php foreach ($category_options as $option): ?>
                                            <option value="<?= (int) $option['item_id'] ?>" <?= $filter_category === (string) $option['item_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($option['item_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="brandFilter">Brand</label>
                                    <select class="form-select" id="brandFilter" name="brand" data-submit-on-change="true">
                                        <option value="">All Brands</option>
                                        <?php foreach ($brand_options as $option): ?>
                                            <option value="<?= (int) $option['item_id'] ?>" <?= $filter_brand === (string) $option['item_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($option['item_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="statusFilter">Status</label>
                                    <select class="form-select" id="statusFilter" name="status" data-submit-on-change="true">
                                        <option value="">All Statuses</option>
                                        <?php foreach ($allowed_statuses as $status_option): ?>
                                            <option value="<?= htmlspecialchars($status_option) ?>" <?= $filter_status === $status_option ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($status_option) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="d-flex align-items-end gap-2">
                                    <button type="submit" class="btn btn-light module-accent-btn">
                                        <i class="fas fa-magnifying-glass"></i> Apply
                                    </button>
                                    <a href="products.php" class="btn btn-outline-light">Reset</a>
                                </div>
                            </div>
                        </form>
                    </section>

                    <section class="module-card module-table-card">
                        <div class="module-table-head">
                            <div>
                                <h2>Products</h2>
                                <p class="module-card-subtitle mb-0">
                                    Showing <?= number_format($display_start) ?>-<?= number_format($display_end) ?> of <?= number_format($total_rows) ?> product<?= $total_rows === 1 ? '' : 's' ?>
                                </p>
                            </div>
                            <a href="product_form.php" class="btn btn-light module-accent-btn">
                                <i class="fas fa-plus"></i> Add Product
                            </a>
                        </div>

                        <?php if (!empty($products)): ?>
                            <div class="module-table-wrap">
                                <div class="table-responsive">
                                    <table class="table table-hover module-table table-stack">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Category</th>
                                                <th>Brand</th>
                                                <th>Supplier</th>
                                                <th>Price</th>
                                                <th>Stock</th>
                                                <th>Status</th>
                                                <th>Added</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($products as $product): ?>
                                                <?php
                                                $stock_qty = (int) $product['stock_qty'];
                                                $reorder_level = max(1, (int) $product['reorder_level']);
                                                $meter_percent = min(100, (int) round(($stock_qty / $reorder_level) * 100));
                                                $meter_class = 'stock-meter-fill-good';
                                                if ($stock_qty <= 0 || $product['status'] === 'Out of Stock') {
                                                    $meter_class = 'stock-meter-fill-out';
                                                    $meter_percent = 100;
                                                } elseif ($stock_qty <= $reorder_level) {
                                                    $meter_class = 'stock-meter-fill-low';
                                                } else {
                                                    $meter_percent = 100;
                                                }
                                                $badges = product_module_product_badges($product);
                                                ?>
                                                <tr>
                                                    <td data-label="Product">
                                                        <div class="product-name"><?= htmlspecialchars($product['product_name']) ?></div>
                                                        <div class="product-subline">#<?= (int) $product['product_id'] ?> &middot; <?= number_format((int) ($product['image_count'] ?? 0)) ?> image<?= (int) ($product['image_count'] ?? 0) === 1 ? '' : 's' ?> &middot; <?= number_format((int) ($product['variation_count'] ?? 0)) ?> variation<?= (int) ($product['variation_count'] ?? 0) === 1 ? '' : 's' ?> &middot; <?= htmlspecialchars(product_module_excerpt($product['description'] ?? '', 72)) ?></div>
                                                    </td>
                                                    <td data-label="Category"><?= htmlspecialchars($product['category_name'] ?? 'Unassigned') ?></td>
                                                    <td data-label="Brand"><?= htmlspecialchars($product['brand_name'] ?? 'Unassigned') ?></td>
                                                    <td data-label="Supplier"><?= htmlspecialchars($product['supplier_name'] ?? 'Unassigned') ?></td>
                                                    <td data-label="Price">
                                                        <div class="price-stack">
                                                            <strong><?= htmlspecialchars(product_module_price_label($product['selling_price'])) ?></strong>
                                                            <span>Cost: <?= htmlspecialchars(product_module_price_label($product['cost_price'], 'Not set')) ?></span>
                                                        </div>
                                                    </td>
                                                    <td data-label="Stock">
                                                        <div class="stock-wrap">
                                                            <strong><?= number_format($stock_qty) ?> units</strong>
                                                            <div class="product-subline">Reorder at <?= number_format((int) $product['reorder_level']) ?></div>
                                                            <div class="stock-meter">
                                                                <span class="<?= $meter_class ?>" style="width: <?= $meter_percent ?>%;"></span>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td data-label="Status">
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <?php foreach ($badges as $badge): ?>
                                                                <span class="<?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                    <td data-label="Added"><?= htmlspecialchars(date('d M Y', strtotime($product['created_at']))) ?></td>
                                                    <td data-label="Actions" class="actions-cell text-end">
                                                        <div class="module-actions justify-content-end">
                                                            <a href="product_view.php?id=<?= (int) $product['product_id'] ?>" class="btn btn-sm btn-outline-light">View</a>
                                                            <a href="product_form.php?id=<?= (int) $product['product_id'] ?>" class="btn btn-sm btn-outline-info">Edit</a>
                                                            <a href="product_codes.php?id=<?= (int) $product['product_id'] ?>" class="btn btn-sm btn-outline-light">Codes</a>
                                                            <a href="product_images.php?id=<?= (int) $product['product_id'] ?>" class="btn btn-sm btn-outline-light">Images</a>
                                                            <a href="product_variations.php?id=<?= (int) $product['product_id'] ?>" class="btn btn-sm btn-outline-light">Variations</a>
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-danger"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#deleteProductModal"
                                                                data-product-id="<?= (int) $product['product_id'] ?>"
                                                                data-product-name="<?= htmlspecialchars($product['product_name']) ?>">
                                                                Delete
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <?php if ($total_pages > 1): ?>
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                ?>
                                <div class="module-pagination">
                                    <div class="module-card-subtitle">Page <?= number_format($page) ?> of <?= number_format($total_pages) ?></div>
                                    <nav aria-label="Product pagination">
                                        <ul class="pagination">
                                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= $page <= 1 ? '#' : $page_url_prefix . 'page=' . ($page - 1) ?>">Previous</a>
                                            </li>
                                            <?php for ($page_number = $start_page; $page_number <= $end_page; $page_number++): ?>
                                                <li class="page-item <?= $page_number === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="<?= $page_url_prefix . 'page=' . $page_number ?>"><?= $page_number ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                                <a class="page-link" href="<?= $page >= $total_pages ? '#' : $page_url_prefix . 'page=' . ($page + 1) ?>">Next</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="module-empty">
                                <i class="fas fa-boxes-stacked"></i>
                                <p>No products matched your current filters.</p>
                                <div class="module-actions justify-content-center">
                                    <a href="products.php" class="btn btn-outline-light">Clear Filters</a>
                                    <a href="product_form.php" class="btn btn-light module-accent-btn">
                                        <i class="fas fa-plus"></i> Add Product
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div class="modal fade" id="deleteProductModal" tabindex="-1" aria-labelledby="deleteProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content module-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteProductModalLabel">Delete Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="products.php">
                    <div class="modal-body">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="product_id" value="" data-delete-product-id>
                        <p class="mb-2">Are you sure you want to remove <strong data-delete-product-name>this product</strong> from your catalog?</p>
                        <p class="text-secondary mb-0">Products linked to orders or customer feedback cannot be deleted.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/product-management.js"></script>
</body>

</html>
