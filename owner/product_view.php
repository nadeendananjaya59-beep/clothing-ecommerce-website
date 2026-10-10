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
$product_schema_issues = $shop_id > 0 ? product_module_schema_issues($conn) : ['Your account is not linked to a shop yet.'];
$schema_issues = $product_schema_issues;

$product_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
if ($product_id <= 0) {
    set_flash('danger', 'Please select a valid product to view.');
    header('Location: products.php');
    exit();
}

$product = empty($product_schema_issues) ? product_module_fetch_product($conn, $product_id, $shop_id) : null;
if (empty($product_schema_issues) && !$product) {
    set_flash('danger', 'The selected product could not be found for your shop.');
    header('Location: products.php');
    exit();
}

$image_schema_issues = empty($product_schema_issues) ? product_module_image_schema_issues($conn) : [];
$image_module_ready = empty($image_schema_issues);
$product_images = $product && $image_module_ready ? product_module_fetch_product_images($conn, $product_id, $shop_id) : [];
$main_image = product_module_get_main_image($product_images);
$image_count = count($product_images);
$variation_schema_issues = empty($product_schema_issues) ? product_module_variation_schema_issues($conn) : [];
$variation_module_ready = empty($variation_schema_issues);
$variation_summary = $product && $variation_module_ready ? product_module_fetch_variation_summary($conn, $product_id, $shop_id) : [
    'total_variations' => 0,
    'color_variations' => 0,
    'size_variations' => 0,
    'combination_variations' => 0,
    'total_variation_stock' => 0,
    'unique_colors' => 0,
    'unique_sizes' => 0
];

$selling_price = (float) ($product['selling_price'] ?? 0);
$cost_price = isset($product['cost_price']) && $product['cost_price'] !== null ? (float) $product['cost_price'] : null;
$discount_price = isset($product['discount_price']) && $product['discount_price'] !== null ? (float) $product['discount_price'] : null;
$margin_value = $cost_price !== null ? $selling_price - $cost_price : null;
$inventory_value = $cost_price !== null ? $cost_price * (int) ($product['stock_qty'] ?? 0) : null;
$badges = $product ? product_module_product_badges($product) : [];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Details | <?= htmlspecialchars($shop_name) ?></title>
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
                    <h1>Product Details</h1>
                    <p>View the full catalog, pricing, supplier, and stock context for a single product record.</p>
                </div>

                <div class="module-backbar">
                    <div class="module-actions">
                        <a href="products.php" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left"></i> Back to Products
                        </a>
                        <a href="product_form.php?id=<?= (int) $product_id ?>" class="btn btn-outline-info">
                            <i class="fas fa-pen-to-square"></i> Edit Product
                        </a>
                        <a href="product_codes.php?id=<?= (int) $product_id ?>" class="btn btn-outline-light">
                            <i class="fas fa-barcode"></i> Codes & Labels
                        </a>
                        <a href="product_images.php?id=<?= (int) $product_id ?>" class="btn btn-outline-light">
                            <i class="fas fa-images"></i> Manage Images
                        </a>
                        <a href="product_variations.php?id=<?= (int) $product_id ?>" class="btn btn-outline-light">
                            <i class="fas fa-layer-group"></i> Manage Variations
                        </a>
                    </div>
                    <span class="module-pill"><i class="fas fa-hashtag"></i> Product #<?= (int) $product_id ?></span>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> module-alert" role="alert">
                        <?= htmlspecialchars($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($schema_issues)): ?>
                    <div class="alert alert-warning module-alert" role="alert">
                        <strong>Schema setup required.</strong>
                        <ul class="mb-2 mt-2">
                            <?php foreach ($schema_issues as $issue): ?>
                                <li><?= htmlspecialchars($issue) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="mb-0">Apply <code>owner/db/product_management_patch.sql</code> or run <code>owner/db/update_schema.php</code> before using this module.</p>
                    </div>
                <?php else: ?>
                    <section class="module-hero">
                        <div>
                            <h2><?= htmlspecialchars($product['product_name']) ?></h2>
                            <p><?= htmlspecialchars($product['description'] !== null && $product['description'] !== '' ? $product['description'] : 'No description has been added for this product yet.') ?></p>
                        </div>
                        <div class="module-hero-actions">
                            <span class="module-pill"><i class="fas fa-images"></i> <?= number_format($image_count) ?> image<?= $image_count === 1 ? '' : 's' ?></span>
                            <span class="module-pill"><i class="fas fa-layer-group"></i> <?= number_format((int) $variation_summary['total_variations']) ?> variation<?= (int) $variation_summary['total_variations'] === 1 ? '' : 's' ?></span>
                            <?php foreach ($badges as $badge): ?>
                                <span class="<?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="module-stats">
                        <article class="module-stat stat-primary">
                            <div class="module-stat-icon"><i class="fas fa-tag"></i></div>
                            <div class="module-stat-value"><?= htmlspecialchars(product_module_price_label($product['selling_price'])) ?></div>
                            <div class="module-stat-label">Selling Price</div>
                        </article>
                        <article class="module-stat stat-success">
                            <div class="module-stat-icon"><i class="fas fa-warehouse"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $product['stock_qty']) ?></div>
                            <div class="module-stat-label">Units in Stock</div>
                        </article>
                        <article class="module-stat stat-warning">
                            <div class="module-stat-icon"><i class="fas fa-arrows-rotate"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $product['reorder_level']) ?></div>
                            <div class="module-stat-label">Reorder Level</div>
                        </article>
                        <article class="module-stat stat-danger">
                            <div class="module-stat-icon"><i class="fas fa-sack-dollar"></i></div>
                            <div class="module-stat-value"><?= htmlspecialchars($margin_value !== null ? product_module_price_label($margin_value) : 'Not set') ?></div>
                            <div class="module-stat-label">Unit Margin</div>
                        </article>
                    </section>

                    <div class="row g-4">
                        <div class="col-xl-8">
                            <section class="module-card mb-4">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Catalog Information</h2>
                                        <p class="module-card-subtitle mb-0">Core product data assigned to this shop.</p>
                                    </div>
                                </div>
                                <ul class="module-detail-list">
                                    <li>
                                        <span class="module-meta-label">Product ID</span>
                                        <span class="module-meta-value">#<?= (int) $product['product_id'] ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Category</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['category_name'] ?? 'Unassigned') ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Brand</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['brand_name'] ?? 'Unassigned') ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Supplier</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['supplier_name'] ?? 'Unassigned') ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Weight</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['weight'] !== null ? $product['weight'] . ' kg' : 'Not set') ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Created At</span>
                                        <span class="module-meta-value"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($product['created_at']))) ?></span>
                                    </li>
                                </ul>
                            </section>

                            <section class="module-card">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Pricing and Stock Context</h2>
                                        <p class="module-card-subtitle mb-0">Commercial details and inventory health at a glance.</p>
                                    </div>
                                </div>
                                <div class="module-summary-grid">
                                    <div>
                                        <ul class="module-detail-list">
                                            <li>
                                                <span class="module-meta-label">Cost Price</span>
                                                <span class="module-meta-value"><?= htmlspecialchars(product_module_price_label($product['cost_price'], 'Not set')) ?></span>
                                            </li>
                                            <li>
                                                <span class="module-meta-label">Selling Price</span>
                                                <span class="module-meta-value"><?= htmlspecialchars(product_module_price_label($product['selling_price'])) ?></span>
                                            </li>
                                            <li>
                                                <span class="module-meta-label">Discount Price</span>
                                                <span class="module-meta-value"><?= htmlspecialchars(product_module_price_label($product['discount_price'], 'No active discount')) ?></span>
                                            </li>
                                            <li>
                                                <span class="module-meta-label">Inventory Value</span>
                                                <span class="module-meta-value"><?= htmlspecialchars($inventory_value !== null ? product_module_price_label($inventory_value) : 'Not set') ?></span>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="module-card">
                                        <div class="module-section-title">Stock Health</div>
                                        <div class="stock-wrap">
                                            <strong><?= number_format((int) $product['stock_qty']) ?> units available</strong>
                                            <div class="product-subline">Reorder threshold: <?= number_format((int) $product['reorder_level']) ?></div>
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
                                            ?>
                                            <div class="stock-meter">
                                                <span class="<?= $meter_class ?>" style="width: <?= $meter_percent ?>%;"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="col-xl-4">
                            <section class="module-card mb-4">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Product Variations</h2>
                                        <p class="module-card-subtitle mb-0">Manage color-only, size-only, and full color-size combinations.</p>
                                    </div>
                                </div>

                                <?php if ($variation_module_ready): ?>
                                    <div class="module-summary-grid narrow">
                                        <div class="module-kpi">
                                            <span>Variations</span>
                                            <strong><?= number_format((int) $variation_summary['total_variations']) ?></strong>
                                        </div>
                                        <div class="module-kpi">
                                            <span>Colors</span>
                                            <strong><?= number_format((int) $variation_summary['unique_colors']) ?></strong>
                                        </div>
                                        <div class="module-kpi">
                                            <span>Sizes</span>
                                            <strong><?= number_format((int) $variation_summary['unique_sizes']) ?></strong>
                                        </div>
                                    </div>

                                    <ul class="module-meta-list mt-3">
                                        <li>
                                            <span class="module-meta-label">Color-Only</span>
                                            <span class="module-meta-value"><?= number_format((int) $variation_summary['color_variations']) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Size-Only</span>
                                            <span class="module-meta-value"><?= number_format((int) $variation_summary['size_variations']) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Combinations</span>
                                            <span class="module-meta-value"><?= number_format((int) $variation_summary['combination_variations']) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Variation Stock</span>
                                            <span class="module-meta-value"><?= number_format((int) $variation_summary['total_variation_stock']) ?></span>
                                        </li>
                                    </ul>

                                    <div class="module-actions mt-3">
                                        <a href="product_variations.php?id=<?= (int) $product_id ?>" class="btn btn-light module-accent-btn">
                                            <i class="fas fa-layer-group"></i> Open Variation Manager
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="module-note">
                                        The `product_variations` table needs to be available before variation management can be enabled for this product.
                                    </div>
                                <?php endif; ?>
                            </section>

                            <section class="module-card mb-4">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Product Images</h2>
                                        <p class="module-card-subtitle mb-0">Manage gallery photos and the main catalog image.</p>
                                    </div>
                                </div>

                                <?php if ($image_module_ready): ?>
                                    <?php if ($main_image): ?>
                                        <div class="gallery-main-preview gallery-main-preview-compact">
                                            <img src="<?= htmlspecialchars($main_image['image_path']) ?>" alt="<?= htmlspecialchars($product['product_name']) ?>">
                                        </div>
                                        <div class="gallery-strip">
                                            <?php foreach (array_slice($product_images, 0, 4) as $image): ?>
                                                <div class="gallery-strip-item">
                                                    <img src="<?= htmlspecialchars($image['image_path']) ?>" alt="<?= htmlspecialchars($product['product_name']) ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="gallery-empty-state gallery-empty-state-compact">
                                            <i class="fas fa-image"></i>
                                            <p>No product images uploaded yet.</p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="module-summary-grid narrow mt-3">
                                        <div class="module-kpi">
                                            <span>Images</span>
                                            <strong><?= number_format($image_count) ?></strong>
                                        </div>
                                        <div class="module-kpi">
                                            <span>Main</span>
                                            <strong><?= $main_image ? 'Ready' : 'Missing' ?></strong>
                                        </div>
                                        <div class="module-kpi">
                                            <span>Limit</span>
                                            <strong><?= number_format(product_module_image_max_per_product()) ?></strong>
                                        </div>
                                    </div>

                                    <div class="module-actions mt-3">
                                        <a href="product_images.php?id=<?= (int) $product_id ?>" class="btn btn-light module-accent-btn">
                                            <i class="fas fa-images"></i> Open Image Manager
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="module-note">
                                        Apply <code>owner/db/product_image_management_patch.sql</code> to enable gallery management and main-image controls for this product.
                                    </div>
                                <?php endif; ?>
                            </section>

                            <section class="module-card mb-4">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Supplier Contact</h2>
                                        <p class="module-card-subtitle mb-0">Current sourcing contact details.</p>
                                    </div>
                                </div>
                                <ul class="module-meta-list">
                                    <li>
                                        <span class="module-meta-label">Supplier</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['supplier_name'] ?? 'Unassigned') ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Phone</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['supplier_contact'] ?? 'Not set') ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Email</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['supplier_email'] ?? 'Not set') ?></span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Address</span>
                                        <span class="module-meta-value"><?= htmlspecialchars($product['supplier_address'] ?? 'Not set') ?></span>
                                    </li>
                                </ul>
                            </section>

                            <section class="module-card">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Danger Zone</h2>
                                        <p class="module-card-subtitle mb-0">Remove products only when they are no longer needed.</p>
                                    </div>
                                </div>
                                <p class="module-note mb-3">Products connected to orders or customer feedback cannot be deleted, which protects reporting and order history integrity.</p>
                                <form method="POST" action="products.php" onsubmit="return confirm('Delete this product from your catalog?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-trash"></i> Delete Product
                                    </button>
                                </form>
                            </section>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/product-management.js"></script>
</body>

</html>
