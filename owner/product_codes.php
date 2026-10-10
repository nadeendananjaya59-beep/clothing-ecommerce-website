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
$schema_issues = $shop_id > 0 ? product_module_code_schema_issues($conn) : ['Your account is not linked to a shop yet.'];

$product_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$search_query = trim((string) ($_GET['search'] ?? ''));
$catalog_limit = 14;

if (empty($schema_issues) && $product_id <= 0 && $search_query !== '') {
    $scanned_product = product_module_find_product_by_scan_value($conn, $shop_id, $search_query);
    if ($scanned_product) {
        header('Location: product_codes.php?id=' . (int) $scanned_product['product_id'] . '&search=' . urlencode($search_query));
        exit();
    }
}

$product = null;
$code_payload = null;
$catalog_products = [];

if (empty($schema_issues)) {
    if ($product_id > 0) {
        $product = product_module_fetch_product($conn, $product_id, $shop_id);
        if (!$product) {
            set_flash('danger', 'The selected product could not be found for your shop.');
            header('Location: product_codes.php');
            exit();
        }

        $product['shop_id'] = $shop_id;
        $code_payload = product_module_product_code_payload($product, $shop_name);
    }

    $catalog_products = product_module_fetch_products_for_codes($conn, $shop_id, $search_query, $catalog_limit);
}

$download_base = $code_payload
    ? product_module_safe_file_token(($product['product_name'] ?? 'product') . '-' . $code_payload['reference_code'])
    : 'product-label';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode & QR Codes | <?= htmlspecialchars($shop_name) ?></title>
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
                    <h1>Barcode & QR Code Management</h1>
                    <p>Generate product barcode labels, QR labels, scan-ready reference codes, and downloadable print assets for your catalog.</p>
                </div>

                <div class="module-backbar">
                    <div class="module-actions">
                        <a href="products.php" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left"></i> Back to Products
                        </a>
                        <?php if ($product): ?>
                            <a href="product_view.php?id=<?= (int) $product['product_id'] ?>" class="btn btn-outline-info">
                                <i class="fas fa-eye"></i> View Product
                            </a>
                            <a href="product_form.php?id=<?= (int) $product['product_id'] ?>" class="btn btn-outline-light">
                                <i class="fas fa-pen-to-square"></i> Edit Product
                            </a>
                        <?php endif; ?>
                    </div>
                    <span class="module-pill"><i class="fas fa-store"></i> Shop #<?= (int) $shop_id ?></span>
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
                            <h2><?= $product ? htmlspecialchars($product['product_name']) : 'Choose a product to generate labels' ?></h2>
                            <p>
                                <?= $product
                                    ? htmlspecialchars('Reference code: ' . $code_payload['reference_code'] . ' · Barcode and QR both resolve to the same scan-ready product code.')
                                    : 'Search by product name, product ID, or scan a printed code into the field below. Most USB and Bluetooth scanners type the code and submit with Enter automatically.' ?>
                            </p>
                        </div>
                        <div class="module-hero-actions">
                            <span class="module-pill"><i class="fas fa-barcode"></i> Printable barcode labels</span>
                            <span class="module-pill"><i class="fas fa-qrcode"></i> QR download support</span>
                            <span class="module-pill"><i class="fas fa-magnifying-glass"></i> Scanner-friendly lookup</span>
                        </div>
                    </section>

                    <section class="module-card">
                        <div class="module-card-header">
                            <div>
                                <h2>Product Scan Support</h2>
                                <p class="module-card-subtitle mb-0">Paste or scan a product code, or search manually by name, status, brand, category, or product ID.</p>
                            </div>
                        </div>

                        <form method="GET" action="product_codes.php" class="module-form">
                            <div class="scan-input-group">
                                <div class="scan-input-field">
                                    <label class="form-label" for="productCodeSearchInput">Search or Scan</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="productCodeSearchInput"
                                        name="search"
                                        placeholder="Example: SHOP-001-PROD-000003 or scan here"
                                        value="<?= htmlspecialchars($search_query) ?>"
                                        autofocus>
                                </div>
                                <div class="scan-input-actions">
                                    <button type="submit" class="btn btn-light module-accent-btn">
                                        <i class="fas fa-bullseye"></i> Find Product
                                    </button>
                                    <a href="product_codes.php" class="btn btn-outline-light">Clear</a>
                                </div>
                            </div>
                        </form>

                        <div class="scan-help-card">
                            <div class="module-note">
                                <p class="mb-2">Each product gets a stable reference code in this format: <strong>SHOP-001-PROD-000123</strong>.</p>
                                <p class="mb-0">Barcode scanners usually enter the code into this field as plain text. QR scanners can also resolve the same value because the QR label encodes the same product reference string.</p>
                            </div>
                        </div>
                    </section>

                    <?php if ($product && $code_payload): ?>
                        <section class="module-stats">
                            <article class="module-stat stat-primary">
                                <div class="module-stat-icon"><i class="fas fa-hashtag"></i></div>
                                <div class="module-stat-value"><?= htmlspecialchars($code_payload['reference_code']) ?></div>
                                <div class="module-stat-label">Product Reference Code</div>
                            </article>
                            <article class="module-stat stat-success">
                                <div class="module-stat-icon"><i class="fas fa-tag"></i></div>
                                <div class="module-stat-value"><?= htmlspecialchars($code_payload['price_label']) ?></div>
                                <div class="module-stat-label">Selling Price</div>
                            </article>
                            <article class="module-stat stat-warning">
                                <div class="module-stat-icon"><i class="fas fa-box-open"></i></div>
                                <div class="module-stat-value"><?= htmlspecialchars($code_payload['stock_label']) ?></div>
                                <div class="module-stat-label">Stock Level</div>
                            </article>
                            <article class="module-stat stat-danger">
                                <div class="module-stat-icon"><i class="fas fa-circle-info"></i></div>
                                <div class="module-stat-value"><?= htmlspecialchars($code_payload['status_label']) ?></div>
                                <div class="module-stat-label">Catalog Status</div>
                            </article>
                        </section>

                        <div class="row g-4">
                            <div class="col-xl-6">
                                <section class="module-card h-100">
                                    <div class="module-card-header">
                                        <div>
                                            <h2>Generate Barcode</h2>
                                            <p class="module-card-subtitle mb-0">Code 39 product label for scanners and shelf tags.</p>
                                        </div>
                                    </div>

                                    <div class="code-preview-artboard" id="barcodeLabelCard">
                                        <div class="label-preview-meta">
                                            <span class="module-pill"><i class="fas fa-barcode"></i> Barcode Label</span>
                                            <span class="product-subline"><?= htmlspecialchars($code_payload['shop_label']) ?></span>
                                        </div>
                                        <div class="code-preview-title"><?= htmlspecialchars($product['product_name']) ?></div>
                                        <div class="product-subline"><?= htmlspecialchars($product['category_name'] ?? 'Unassigned') ?> &middot; <?= htmlspecialchars($product['brand_name'] ?? 'Unassigned') ?></div>
                                        <svg
                                            id="barcodeSvg"
                                            class="js-barcode-svg"
                                            data-barcode-value="<?= htmlspecialchars($code_payload['barcode_value']) ?>"
                                            data-barcode-text="<?= htmlspecialchars($code_payload['barcode_value']) ?>"
                                            data-barcode-height="82"
                                            data-barcode-margin="12"
                                            data-barcode-line-color="#f8fafc"
                                            data-barcode-background="transparent"
                                            aria-label="Product barcode preview"></svg>
                                        <div class="barcode-value"><?= htmlspecialchars($code_payload['barcode_value']) ?></div>
                                        <div class="product-subline"><?= htmlspecialchars($code_payload['price_label']) ?> &middot; <?= htmlspecialchars($code_payload['stock_label']) ?></div>
                                    </div>

                                    <div class="module-actions mt-3">
                                        <button type="button" class="btn btn-light module-accent-btn" data-download-svg-target="#barcodeSvg" data-download-filename="<?= htmlspecialchars($download_base) ?>-barcode.svg">
                                            <i class="fas fa-download"></i> Download Barcode Image
                                        </button>
                                        <button type="button" class="btn btn-outline-light" data-print-source="#barcodeLabelCard" data-print-title="<?= htmlspecialchars($product['product_name']) ?> Barcode Label">
                                            <i class="fas fa-print"></i> Print Barcode Label
                                        </button>
                                    </div>
                                </section>
                            </div>

                            <div class="col-xl-6">
                                <section class="module-card h-100">
                                    <div class="module-card-header">
                                        <div>
                                            <h2>Generate QR Code</h2>
                                            <p class="module-card-subtitle mb-0">Square label for compact packaging and quick mobile scans.</p>
                                        </div>
                                    </div>

                                    <div class="code-preview-artboard" id="qrLabelCard">
                                        <div class="label-preview-meta">
                                            <span class="module-pill"><i class="fas fa-qrcode"></i> QR Label</span>
                                            <span class="product-subline"><?= htmlspecialchars($code_payload['shop_label']) ?></span>
                                        </div>
                                        <div class="code-preview-title"><?= htmlspecialchars($product['product_name']) ?></div>
                                        <div class="qr-preview-holder js-qr-target" id="qrCodeTarget" data-qr-value="<?= htmlspecialchars($code_payload['qr_value']) ?>" data-qr-size="210" aria-label="Product QR code preview"></div>
                                        <div class="barcode-value"><?= htmlspecialchars($code_payload['qr_value']) ?></div>
                                        <div class="product-subline"><?= htmlspecialchars($code_payload['status_label']) ?> &middot; <?= htmlspecialchars($code_payload['price_label']) ?></div>
                                    </div>

                                    <div class="module-actions mt-3">
                                        <button type="button" class="btn btn-light module-accent-btn" data-download-svg-target="#qrCodeTarget" data-download-filename="<?= htmlspecialchars($download_base) ?>-qr.svg">
                                            <i class="fas fa-download"></i> Download QR Code Image
                                        </button>
                                        <button type="button" class="btn btn-outline-light" data-print-source="#qrLabelCard" data-print-title="<?= htmlspecialchars($product['product_name']) ?> QR Label">
                                            <i class="fas fa-print"></i> Print QR Label
                                        </button>
                                    </div>
                                </section>
                            </div>
                        </div>

                        <div class="row g-4 mt-1">
                            <div class="col-xl-7">
                                <section class="module-card">
                                    <div class="module-card-header">
                                        <div>
                                            <h2>Code Details</h2>
                                            <p class="module-card-subtitle mb-0">Reference values used for both label types and scanner lookup.</p>
                                        </div>
                                    </div>
                                    <ul class="module-detail-list">
                                        <li>
                                            <span class="module-meta-label">Reference Code</span>
                                            <span class="module-meta-value"><?= htmlspecialchars($code_payload['reference_code']) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Barcode Encoded Value</span>
                                            <span class="module-meta-value"><?= htmlspecialchars($code_payload['barcode_value']) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">QR Encoded Value</span>
                                            <span class="module-meta-value"><?= htmlspecialchars($code_payload['qr_value']) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Suggested File Base</span>
                                            <span class="module-meta-value"><?= htmlspecialchars($download_base) ?></span>
                                        </li>
                                    </ul>
                                </section>
                            </div>

                            <div class="col-xl-5">
                                <section class="module-card h-100">
                                    <div class="module-card-header">
                                        <div>
                                            <h2>Label Usage Notes</h2>
                                            <p class="module-card-subtitle mb-0">A few practical guidelines for printing and scanning.</p>
                                        </div>
                                    </div>
                                    <div class="module-note">
                                        <p class="mb-3">Barcode labels work best on shelf tags, hang tags, and warehouse picking lists where laser scanners are common.</p>
                                        <p class="mb-3">QR labels are a good fit for compact packaging and mobile scans because the same product reference fits into a smaller square area.</p>
                                        <p class="mb-0">Both labels resolve to the same product code, so your staff can search or scan either format and land on the same catalog item.</p>
                                    </div>
                                </section>
                            </div>
                        </div>
                    <?php else: ?>
                        <section class="module-card">
                            <div class="module-empty">
                                <i class="fas fa-barcode"></i>
                                <p>Select a product from the list below to generate its barcode and QR code labels.</p>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="module-card module-table-card mt-4">
                        <div class="module-table-head">
                            <div>
                                <h2><?= $search_query !== '' ? 'Search Results' : 'Recent Products for Labeling' ?></h2>
                                <p class="module-card-subtitle mb-0">Choose any catalog item to open its barcode and QR code workspace.</p>
                            </div>
                        </div>

                        <?php if (!empty($catalog_products)): ?>
                            <div class="module-table-wrap">
                                <div class="table-responsive">
                                    <table class="table table-hover module-table table-stack">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Reference Code</th>
                                                <th>Price</th>
                                                <th>Stock</th>
                                                <th>Status</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($catalog_products as $catalog_product): ?>
                                                <?php $reference_code = product_module_product_reference_code($catalog_product['shop_id'], $catalog_product['product_id']); ?>
                                                <tr>
                                                    <td data-label="Product">
                                                        <div class="product-name"><?= htmlspecialchars($catalog_product['product_name']) ?></div>
                                                        <div class="product-subline"><?= htmlspecialchars($catalog_product['category_name']) ?> &middot; <?= htmlspecialchars($catalog_product['brand_name']) ?></div>
                                                    </td>
                                                    <td data-label="Reference Code">
                                                        <span class="barcode-value barcode-value-compact"><?= htmlspecialchars($reference_code) ?></span>
                                                    </td>
                                                    <td data-label="Price"><?= htmlspecialchars(product_module_price_label($catalog_product['selling_price'])) ?></td>
                                                    <td data-label="Stock"><?= number_format((int) $catalog_product['stock_qty']) ?> units</td>
                                                    <td data-label="Status">
                                                        <?php foreach (product_module_product_badges($catalog_product) as $badge): ?>
                                                            <span class="<?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                                        <?php endforeach; ?>
                                                    </td>
                                                    <td data-label="Actions" class="actions-cell text-end">
                                                        <div class="module-actions justify-content-end">
                                                            <a href="product_codes.php?id=<?= (int) $catalog_product['product_id'] ?>" class="btn btn-sm btn-outline-light">Open Codes</a>
                                                            <a href="product_view.php?id=<?= (int) $catalog_product['product_id'] ?>" class="btn btn-sm btn-outline-info">View</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="module-empty">
                                <i class="fas fa-magnifying-glass"></i>
                                <p>No products matched the current search or scan value.</p>
                                <div class="module-actions justify-content-center">
                                    <a href="product_codes.php" class="btn btn-outline-light">Show Recent Products</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script src="assets/js/product-management.js"></script>
</body>

</html>
