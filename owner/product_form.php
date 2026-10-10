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

$product_id = 0;
if (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
    $product_id = (int) $_GET['id'];
} elseif (isset($_POST['product_id']) && ctype_digit((string) $_POST['product_id'])) {
    $product_id = (int) $_POST['product_id'];
}

$is_edit = $product_id > 0;
$product = null;
$form_data = product_module_default_form_data();
$errors = [];
$category_options = [];
$brand_options = [];
$supplier_options = [];

if (empty($schema_issues)) {
    $category_options = product_module_fetch_lookup_options($conn, 'categories', $shop_id);
    $brand_options = product_module_fetch_lookup_options($conn, 'brands', $shop_id);
    $supplier_options = product_module_fetch_lookup_options($conn, 'suppliers', $shop_id);

    if ($is_edit) {
        $product = product_module_fetch_product($conn, $product_id, $shop_id);
        if (!$product) {
            set_flash('danger', 'The selected product could not be found for your shop.');
            header('Location: products.php');
            exit();
        }

        $form_data = product_module_fill_form_data($product);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($schema_issues)) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors['form'] = 'Security verification failed. Please submit the form again.';
    } else {
        [$form_data, $errors] = product_module_validate_product($conn, $_POST, $shop_id);

        if (empty($errors)) {
            $save_result = product_module_save_product($conn, $shop_id, $form_data, $product_id);
            if ($save_result['success']) {
                set_flash('success', $save_result['message']);
                header('Location: product_view.php?id=' . (int) $save_result['product_id']);
                exit();
            }

            $errors['form'] = $save_result['message'];
        }
    }
}

$page_title = $is_edit ? 'Edit Product' : 'Add Product';
$page_intro = $is_edit
    ? 'Update pricing, stock, and placement details for this product.'
    : 'Create a new catalog item with shop-scoped inventory, pricing, and status control.';
$created_at = $product && !empty($product['created_at']) ? date('d M Y, h:i A', strtotime($product['created_at'])) : 'Not created yet';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> | <?= htmlspecialchars($shop_name) ?></title>
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
                    <h1><?= htmlspecialchars($page_title) ?></h1>
                    <p><?= htmlspecialchars($page_intro) ?></p>
                </div>

                <div class="module-backbar">
                    <div class="module-actions">
                        <a href="products.php" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left"></i> Back to Products
                        </a>
                        <?php if ($is_edit): ?>
                            <a href="product_view.php?id=<?= (int) $product_id ?>" class="btn btn-outline-info">
                                <i class="fas fa-eye"></i> View Details
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
                        <?php endif; ?>
                    </div>
                    <span class="module-pill"><i class="fas fa-hashtag"></i> Shop <?= (int) $shop_id ?></span>
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
                    <?php if (isset($errors['form'])): ?>
                        <div class="alert alert-danger module-alert" role="alert">
                            <?= htmlspecialchars($errors['form']) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="product_form.php<?= $is_edit ? '?id=' . (int) $product_id : '' ?>" class="module-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">

                        <div class="row g-4">
                            <div class="col-xl-8">
                                <section class="module-card mb-4">
                                    <div class="module-section-title">Basic Information</div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label" for="productNameInput">Product Name</label>
                                            <input
                                                type="text"
                                                class="form-control <?= isset($errors['product_name']) ? 'is-invalid' : '' ?>"
                                                id="productNameInput"
                                                name="product_name"
                                                maxlength="150"
                                                placeholder="Slim Fit Linen Shirt"
                                                value="<?= htmlspecialchars($form_data['product_name']) ?>">
                                            <?php if (isset($errors['product_name'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['product_name']) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label" for="descriptionInput">Description</label>
                                            <textarea
                                                class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                                                id="descriptionInput"
                                                name="description"
                                                rows="5"
                                                placeholder="Add a short overview, fabric details, sizing notes, or styling highlights."><?= htmlspecialchars($form_data['description']) ?></textarea>
                                            <?php if (isset($errors['description'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="categorySelect">Category</label>
                                            <select class="form-select <?= isset($errors['category_id']) ? 'is-invalid' : '' ?>" id="categorySelect" name="category_id">
                                                <option value="">Choose category</option>
                                                <?php foreach ($category_options as $option): ?>
                                                    <option value="<?= (int) $option['item_id'] ?>" <?= $form_data['category_id'] === (string) $option['item_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($option['item_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (isset($errors['category_id'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['category_id']) ?></div>
                                            <?php else: ?>
                                                <div class="form-text">Optional, but recommended for catalog filters.</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="brandSelect">Brand</label>
                                            <select class="form-select <?= isset($errors['brand_id']) ? 'is-invalid' : '' ?>" id="brandSelect" name="brand_id">
                                                <option value="">Choose brand</option>
                                                <?php foreach ($brand_options as $option): ?>
                                                    <option value="<?= (int) $option['item_id'] ?>" <?= $form_data['brand_id'] === (string) $option['item_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($option['item_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (isset($errors['brand_id'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['brand_id']) ?></div>
                                            <?php else: ?>
                                                <div class="form-text">Helps owners and customers browse faster.</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="supplierSelect">Supplier</label>
                                            <select class="form-select <?= isset($errors['supplier_id']) ? 'is-invalid' : '' ?>" id="supplierSelect" name="supplier_id">
                                                <option value="">Choose supplier</option>
                                                <?php foreach ($supplier_options as $option): ?>
                                                    <option value="<?= (int) $option['item_id'] ?>" <?= $form_data['supplier_id'] === (string) $option['item_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($option['item_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (isset($errors['supplier_id'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['supplier_id']) ?></div>
                                            <?php else: ?>
                                                <div class="form-text">Useful for replenishment and audit trails.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </section>

                                <section class="module-card mb-4">
                                    <div class="module-section-title">Pricing and Inventory</div>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label" for="costPriceInput">Cost Price</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="form-control <?= isset($errors['cost_price']) ? 'is-invalid' : '' ?>"
                                                id="costPriceInput"
                                                name="cost_price"
                                                placeholder="0.00"
                                                value="<?= htmlspecialchars($form_data['cost_price']) ?>">
                                            <?php if (isset($errors['cost_price'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['cost_price']) ?></div>
                                            <?php else: ?>
                                                <div class="form-text">Optional internal cost reference.</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="sellingPriceInput">Selling Price</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="form-control <?= isset($errors['selling_price']) ? 'is-invalid' : '' ?>"
                                                id="sellingPriceInput"
                                                name="selling_price"
                                                placeholder="0.00"
                                                value="<?= htmlspecialchars($form_data['selling_price']) ?>">
                                            <?php if (isset($errors['selling_price'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['selling_price']) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="discountPriceInput">Discount Price</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="form-control <?= isset($errors['discount_price']) ? 'is-invalid' : '' ?>"
                                                id="discountPriceInput"
                                                name="discount_price"
                                                placeholder="0.00"
                                                value="<?= htmlspecialchars($form_data['discount_price']) ?>">
                                            <?php if (isset($errors['discount_price'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['discount_price']) ?></div>
                                            <?php else: ?>
                                                <div class="form-text">Leave empty if no active discount applies.</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="stockQtyInput">Stock Quantity</label>
                                            <input
                                                type="number"
                                                step="1"
                                                min="0"
                                                class="form-control <?= isset($errors['stock_qty']) ? 'is-invalid' : '' ?>"
                                                id="stockQtyInput"
                                                name="stock_qty"
                                                value="<?= htmlspecialchars($form_data['stock_qty']) ?>">
                                            <?php if (isset($errors['stock_qty'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['stock_qty']) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="reorderLevelInput">Reorder Level</label>
                                            <input
                                                type="number"
                                                step="1"
                                                min="0"
                                                class="form-control <?= isset($errors['reorder_level']) ? 'is-invalid' : '' ?>"
                                                id="reorderLevelInput"
                                                name="reorder_level"
                                                value="<?= htmlspecialchars($form_data['reorder_level']) ?>">
                                            <?php if (isset($errors['reorder_level'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['reorder_level']) ?></div>
                                            <?php else: ?>
                                                <div class="form-text">Used to flag low stock in the module.</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label" for="weightInput">Weight</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="form-control <?= isset($errors['weight']) ? 'is-invalid' : '' ?>"
                                                id="weightInput"
                                                name="weight"
                                                placeholder="0.00"
                                                value="<?= htmlspecialchars($form_data['weight']) ?>">
                                            <?php if (isset($errors['weight'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($errors['weight']) ?></div>
                                            <?php else: ?>
                                                <div class="form-text">Optional shipping or warehouse reference.</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label" for="statusSelect">Status</label>
                                            <select class="form-select" id="statusSelect" name="status">
                                                <option value="Available" <?= $form_data['status'] === 'Available' ? 'selected' : '' ?>>Available</option>
                                                <option value="Out of Stock" <?= $form_data['status'] === 'Out of Stock' ? 'selected' : '' ?>>Out of Stock</option>
                                            </select>
                                            <div class="form-text" id="stockStatusHint">This product will be saved as Available.</div>
                                        </div>
                                    </div>
                                </section>

                                <section class="module-card">
                                    <div class="module-actions">
                                        <a href="products.php" class="btn btn-outline-light">Cancel</a>
                                        <button type="submit" class="btn btn-light module-accent-btn">
                                            <i class="fas fa-floppy-disk"></i> <?= $is_edit ? 'Update Product' : 'Save Product' ?>
                                        </button>
                                    </div>
                                </section>
                            </div>

                            <div class="col-xl-4">
                                <section class="module-card mb-4">
                                    <div class="module-card-header">
                                        <div>
                                            <h2>Record Summary</h2>
                                            <p class="module-card-subtitle mb-0">Quick context while you edit.</p>
                                        </div>
                                    </div>
                                    <ul class="module-meta-list">
                                        <li>
                                            <span class="module-meta-label">Mode</span>
                                            <span class="module-meta-value"><?= $is_edit ? 'Editing existing product' : 'Creating new product' ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Shop</span>
                                            <span class="module-meta-value">#<?= (int) $shop_id ?> &middot; <?= htmlspecialchars($shop_name) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Product ID</span>
                                            <span class="module-meta-value"><?= $is_edit ? '#' . (int) $product_id : 'Assigned after save' ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Created</span>
                                            <span class="module-meta-value"><?= htmlspecialchars($created_at) ?></span>
                                        </li>
                                        <li>
                                            <span class="module-meta-label">Margin Preview</span>
                                            <span class="module-meta-value" id="marginPreviewValue">LKR 0.00</span>
                                        </li>
                                    </ul>
                                </section>

                                <section class="module-card mb-4">
                                    <div class="module-card-header">
                                        <div>
                                            <h2>Lookup Availability</h2>
                                            <p class="module-card-subtitle mb-0">Reference records currently available to this shop.</p>
                                        </div>
                                    </div>
                                    <div class="module-summary-grid narrow">
                                        <div class="module-kpi">
                                            <span>Categories</span>
                                            <strong><?= number_format(count($category_options)) ?></strong>
                                        </div>
                                        <div class="module-kpi">
                                            <span>Brands</span>
                                            <strong><?= number_format(count($brand_options)) ?></strong>
                                        </div>
                                        <div class="module-kpi">
                                            <span>Suppliers</span>
                                            <strong><?= number_format(count($supplier_options)) ?></strong>
                                        </div>
                                    </div>
                                </section>

                                <section class="module-card">
                                    <div class="module-card-header">
                                        <div>
                                            <h2>Owner Notes</h2>
                                            <p class="module-card-subtitle mb-0">A few guardrails while maintaining the catalog.</p>
                                        </div>
                                    </div>
                                    <div class="module-note">
                                        <p class="mb-3">If stock quantity reaches zero, the module will automatically save the product as Out of Stock.</p>
                                        <p class="mb-3">Low stock is flagged whenever stock is greater than zero and less than or equal to the reorder level.</p>
                                        <p class="mb-0">Category, brand, and supplier assignments are optional, but they make reporting and filtering much more useful.</p>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/product-management.js"></script>
</body>

</html>
