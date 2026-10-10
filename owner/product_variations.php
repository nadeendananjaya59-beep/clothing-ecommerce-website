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

$product_id = 0;
if (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
    $product_id = (int) $_GET['id'];
} elseif (isset($_POST['product_id']) && ctype_digit((string) $_POST['product_id'])) {
    $product_id = (int) $_POST['product_id'];
}

if ($product_id <= 0) {
    set_flash('danger', 'Please choose a valid product before managing variations.');
    header('Location: products.php');
    exit();
}

$product_schema_issues = $shop_id > 0 ? product_module_schema_issues($conn) : ['Your account is not linked to a shop yet.'];
$variation_schema_issues = product_module_variation_schema_issues($conn);
$schema_issues = array_merge($product_schema_issues, $variation_schema_issues);

$product = empty($product_schema_issues) ? product_module_fetch_product($conn, $product_id, $shop_id) : null;
if (empty($product_schema_issues) && !$product) {
    set_flash('danger', 'The selected product could not be found for your shop.');
    header('Location: products.php');
    exit();
}

$labels = product_module_variation_type_labels();

$color_form_data = ['color' => '', 'stock_qty' => '0'];
$size_form_data = ['size' => '', 'stock_qty' => '0'];
$combination_form_data = ['color' => '', 'size' => '', 'stock_qty' => '0'];
$color_form_errors = [];
$size_form_errors = [];
$combination_form_errors = [];
$inline_messages = ['color' => '', 'size' => '', 'combination' => ''];

$edit_color_id = isset($_GET['edit_color']) && ctype_digit((string) $_GET['edit_color']) ? (int) $_GET['edit_color'] : 0;
$edit_size_id = isset($_GET['edit_size']) && ctype_digit((string) $_GET['edit_size']) ? (int) $_GET['edit_size'] : 0;
$edit_combo_id = isset($_GET['edit_combo']) && ctype_digit((string) $_GET['edit_combo']) ? (int) $_GET['edit_combo'] : 0;

$edit_color_variation = null;
$edit_size_variation = null;
$edit_combo_variation = null;

if (empty($schema_issues)) {
    if ($edit_color_id > 0) {
        $edit_color_variation = product_module_fetch_product_variation($conn, $edit_color_id, $product_id, $shop_id);
        if (!$edit_color_variation || !product_module_variation_matches_type($edit_color_variation, 'color')) {
            set_flash('danger', 'The requested color variation could not be loaded.');
            header('Location: product_variations.php?id=' . $product_id . '#color-variations');
            exit();
        }
        $color_form_data = [
            'color' => $edit_color_variation['color'],
            'stock_qty' => (string) $edit_color_variation['stock_qty']
        ];
    }

    if ($edit_size_id > 0) {
        $edit_size_variation = product_module_fetch_product_variation($conn, $edit_size_id, $product_id, $shop_id);
        if (!$edit_size_variation || !product_module_variation_matches_type($edit_size_variation, 'size')) {
            set_flash('danger', 'The requested size variation could not be loaded.');
            header('Location: product_variations.php?id=' . $product_id . '#size-variations');
            exit();
        }
        $size_form_data = [
            'size' => $edit_size_variation['size'],
            'stock_qty' => (string) $edit_size_variation['stock_qty']
        ];
    }

    if ($edit_combo_id > 0) {
        $edit_combo_variation = product_module_fetch_product_variation($conn, $edit_combo_id, $product_id, $shop_id);
        if (!$edit_combo_variation || !product_module_variation_matches_type($edit_combo_variation, 'combination')) {
            set_flash('danger', 'The requested combination variation could not be loaded.');
            header('Location: product_variations.php?id=' . $product_id . '#combination-variations');
            exit();
        }
        $combination_form_data = [
            'color' => $edit_combo_variation['color'],
            'size' => $edit_combo_variation['size'],
            'stock_qty' => (string) $edit_combo_variation['stock_qty']
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security verification failed. Please try again.');
        header('Location: product_variations.php?id=' . $product_id);
        exit();
    }

    if (!empty($schema_issues)) {
        set_flash('danger', 'The product variations module cannot run until the required schema is available.');
        header('Location: product_variations.php?id=' . $product_id);
        exit();
    }

    $action = $_POST['action'] ?? '';

    if (in_array($action, ['delete_color_variation', 'delete_size_variation', 'delete_combo_variation'], true)) {
        $variation_id = (int) ($_POST['variation_id'] ?? 0);
        $variation_label = $action === 'delete_color_variation'
            ? $labels['color']
            : ($action === 'delete_size_variation' ? $labels['size'] : $labels['combination']);
        $result = product_module_delete_product_variation($conn, $variation_id, $product_id, $shop_id, $variation_label);

        set_flash($result['success'] ? 'success' : 'danger', $result['message']);
        $anchor = $action === 'delete_color_variation'
            ? '#color-variations'
            : ($action === 'delete_size_variation' ? '#size-variations' : '#combination-variations');
        header('Location: product_variations.php?id=' . $product_id . $anchor);
        exit();
    }

    if (in_array($action, ['add_color_variation', 'update_color_variation'], true)) {
        [$data, $errors] = product_module_validate_variation_input($_POST, 'color');
        $color_form_data = [
            'color' => $data['color'],
            'stock_qty' => $data['stock_qty']
        ];
        $color_form_errors = $errors;

        if (empty($errors)) {
            $variation_id = (int) ($_POST['variation_id'] ?? 0);
            $result = product_module_save_product_variation($conn, $variation_id, $product_id, $shop_id, $data, 'color');
            if ($result['success']) {
                set_flash('success', $result['message']);
                header('Location: product_variations.php?id=' . $product_id . '#color-variations');
                exit();
            }
            $inline_messages['color'] = $result['message'];
        }
    }

    if (in_array($action, ['add_size_variation', 'update_size_variation'], true)) {
        [$data, $errors] = product_module_validate_variation_input($_POST, 'size');
        $size_form_data = [
            'size' => $data['size'],
            'stock_qty' => $data['stock_qty']
        ];
        $size_form_errors = $errors;

        if (empty($errors)) {
            $variation_id = (int) ($_POST['variation_id'] ?? 0);
            $result = product_module_save_product_variation($conn, $variation_id, $product_id, $shop_id, $data, 'size');
            if ($result['success']) {
                set_flash('success', $result['message']);
                header('Location: product_variations.php?id=' . $product_id . '#size-variations');
                exit();
            }
            $inline_messages['size'] = $result['message'];
        }
    }

    if (in_array($action, ['add_combo_variation', 'update_combo_variation'], true)) {
        [$data, $errors] = product_module_validate_variation_input($_POST, 'combination');
        $combination_form_data = [
            'color' => $data['color'],
            'size' => $data['size'],
            'stock_qty' => $data['stock_qty']
        ];
        $combination_form_errors = $errors;

        if (empty($errors)) {
            $variation_id = (int) ($_POST['variation_id'] ?? 0);
            $result = product_module_save_product_variation($conn, $variation_id, $product_id, $shop_id, $data, 'combination');
            if ($result['success']) {
                set_flash('success', $result['message']);
                header('Location: product_variations.php?id=' . $product_id . '#combination-variations');
                exit();
            }
            $inline_messages['combination'] = $result['message'];
        }
    }
}

$variation_summary = empty($schema_issues) ? product_module_fetch_variation_summary($conn, $product_id, $shop_id) : [];
$color_variations = empty($schema_issues) ? product_module_fetch_product_variations($conn, $product_id, $shop_id, 'color') : [];
$size_variations = empty($schema_issues) ? product_module_fetch_product_variations($conn, $product_id, $shop_id, 'size') : [];
$combination_variations = empty($schema_issues) ? product_module_fetch_product_variations($conn, $product_id, $shop_id, 'combination') : [];
$suggested_colors = empty($schema_issues) ? product_module_fetch_variation_labels($conn, $product_id, $shop_id, 'color') : [];
$suggested_sizes = empty($schema_issues) ? product_module_fetch_variation_labels($conn, $product_id, $shop_id, 'size') : [];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Variations | <?= htmlspecialchars($shop_name) ?></title>
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
                    <h1>Product Variations Management</h1>
                    <p>Track standalone color or size variants, manage color-size combinations, and synchronize parent stock from variation inventory.</p>
                </div>

                <div class="module-backbar">
                    <div class="module-actions">
                        <a href="product_view.php?id=<?= (int) $product_id ?>" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left"></i> Back to Product
                        </a>
                        <a href="product_form.php?id=<?= (int) $product_id ?>" class="btn btn-outline-info">
                            <i class="fas fa-pen-to-square"></i> Edit Product
                        </a>
                        <a href="product_images.php?id=<?= (int) $product_id ?>" class="btn btn-outline-light">
                            <i class="fas fa-images"></i> Manage Images
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
                        <p class="mb-0">Make sure the product tables are imported and shop-aware schema has been applied before using variation management.</p>
                    </div>
                <?php else: ?>
                    <section class="module-hero">
                        <div>
                            <h2><?= htmlspecialchars($product['product_name']) ?></h2>
                            <p><?= htmlspecialchars($product['description'] !== null && $product['description'] !== '' ? $product['description'] : 'No description has been added for this product yet.') ?></p>
                        </div>
                        <div class="module-hero-actions">
                            <span class="module-pill"><i class="fas fa-palette"></i> <?= number_format((int) $variation_summary['unique_colors']) ?> colors</span>
                            <span class="module-pill"><i class="fas fa-ruler"></i> <?= number_format((int) $variation_summary['unique_sizes']) ?> sizes</span>
                            <span class="module-pill"><i class="fas fa-boxes-stacked"></i> Stock sync enabled</span>
                        </div>
                    </section>

                    <section class="module-stats">
                        <article class="module-stat stat-primary">
                            <div class="module-stat-icon"><i class="fas fa-boxes-stacked"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $variation_summary['total_variations']) ?></div>
                            <div class="module-stat-label">Total Variations</div>
                        </article>
                        <article class="module-stat stat-success">
                            <div class="module-stat-icon"><i class="fas fa-layer-group"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $variation_summary['combination_variations']) ?></div>
                            <div class="module-stat-label">Combinations</div>
                        </article>
                        <article class="module-stat stat-warning">
                            <div class="module-stat-icon"><i class="fas fa-box-open"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $variation_summary['total_variation_stock']) ?></div>
                            <div class="module-stat-label">Variation Stock</div>
                        </article>
                        <article class="module-stat stat-danger">
                            <div class="module-stat-icon"><i class="fas fa-shirt"></i></div>
                            <div class="module-stat-value"><?= number_format((int) $product['stock_qty']) ?></div>
                            <div class="module-stat-label">Synced Product Stock</div>
                        </article>
                    </section>

                    <div class="alert alert-info module-alert" role="alert">
                        Saving or deleting a variation will automatically recalculate the parent product stock using the total of all variation rows for this product.
                    </div>

                    <div class="variation-chip-list">
                        <div class="variation-chip-group">
                            <span class="module-meta-label">Color Labels</span>
                            <div class="variation-chip-items">
                                <?php if (!empty($suggested_colors)): ?>
                                    <?php foreach ($suggested_colors as $color_label): ?>
                                        <span class="variation-chip"><?= htmlspecialchars($color_label) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="variation-chip variation-chip-muted">No colors yet</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="variation-chip-group">
                            <span class="module-meta-label">Size Labels</span>
                            <div class="variation-chip-items">
                                <?php if (!empty($suggested_sizes)): ?>
                                    <?php foreach ($suggested_sizes as $size_label): ?>
                                        <span class="variation-chip"><?= htmlspecialchars($size_label) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="variation-chip variation-chip-muted">No sizes yet</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-xl-6" id="color-variations">
                            <section class="module-card h-100">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Color Variations</h2>
                                        <p class="module-card-subtitle mb-0">Use this when the product varies by color only.</p>
                                    </div>
                                </div>

                                <?php if ($inline_messages['color'] !== ''): ?>
                                    <div class="alert alert-danger module-alert py-2" role="alert">
                                        <?= htmlspecialchars($inline_messages['color']) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="product_variations.php?id=<?= (int) $product_id ?>#color-variations" class="module-form mb-4">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                    <input type="hidden" name="variation_id" value="<?= (int) ($edit_color_variation['variation_id'] ?? 0) ?>">
                                    <input type="hidden" name="action" value="<?= $edit_color_variation ? 'update_color_variation' : 'add_color_variation' ?>">

                                    <div class="variation-form-grid">
                                        <div>
                                            <label class="form-label" for="colorVariationInput">Color</label>
                                            <input
                                                type="text"
                                                class="form-control <?= isset($color_form_errors['color']) ? 'is-invalid' : '' ?>"
                                                id="colorVariationInput"
                                                name="color"
                                                maxlength="50"
                                                placeholder="Navy Blue"
                                                value="<?= htmlspecialchars($color_form_data['color']) ?>">
                                            <?php if (isset($color_form_errors['color'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($color_form_errors['color']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <label class="form-label" for="colorVariationStockInput">Stock</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="1"
                                                class="form-control <?= isset($color_form_errors['stock_qty']) ? 'is-invalid' : '' ?>"
                                                id="colorVariationStockInput"
                                                name="stock_qty"
                                                value="<?= htmlspecialchars($color_form_data['stock_qty']) ?>">
                                            <?php if (isset($color_form_errors['stock_qty'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($color_form_errors['stock_qty']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="module-actions mt-3">
                                        <button type="submit" class="btn btn-light module-accent-btn">
                                            <i class="fas fa-floppy-disk"></i> <?= $edit_color_variation ? 'Update Color Variation' : 'Add Color Variation' ?>
                                        </button>
                                        <?php if ($edit_color_variation): ?>
                                            <a href="product_variations.php?id=<?= (int) $product_id ?>#color-variations" class="btn btn-outline-light">Cancel</a>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <?php if (!empty($color_variations)): ?>
                                    <div class="variation-table-wrap">
                                        <div class="table-responsive">
                                            <table class="table table-hover module-table table-stack">
                                                <thead>
                                                    <tr>
                                                        <th>Color</th>
                                                        <th>Stock</th>
                                                        <th class="text-end">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($color_variations as $variation): ?>
                                                        <tr>
                                                            <td data-label="Color">
                                                                <strong><?= htmlspecialchars($variation['color']) ?></strong>
                                                            </td>
                                                            <td data-label="Stock"><?= number_format((int) $variation['stock_qty']) ?> units</td>
                                                            <td data-label="Actions" class="actions-cell text-end">
                                                                <div class="module-actions justify-content-end">
                                                                    <a href="product_variations.php?id=<?= (int) $product_id ?>&edit_color=<?= (int) $variation['variation_id'] ?>#color-variations" class="btn btn-sm btn-outline-info">Edit</a>
                                                                    <form method="POST" action="product_variations.php?id=<?= (int) $product_id ?>#color-variations" class="module-inline-form" onsubmit="return confirm('Delete this color variation?');">
                                                                        <?= csrf_field() ?>
                                                                        <input type="hidden" name="action" value="delete_color_variation">
                                                                        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                                                        <input type="hidden" name="variation_id" value="<?= (int) $variation['variation_id'] ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="variation-empty-state">
                                        <i class="fas fa-palette"></i>
                                        <p>No standalone color variations have been added yet.</p>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>

                        <div class="col-xl-6" id="size-variations">
                            <section class="module-card h-100">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Size Variations</h2>
                                        <p class="module-card-subtitle mb-0">Use this when the product varies by size only.</p>
                                    </div>
                                </div>

                                <?php if ($inline_messages['size'] !== ''): ?>
                                    <div class="alert alert-danger module-alert py-2" role="alert">
                                        <?= htmlspecialchars($inline_messages['size']) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="product_variations.php?id=<?= (int) $product_id ?>#size-variations" class="module-form mb-4">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                    <input type="hidden" name="variation_id" value="<?= (int) ($edit_size_variation['variation_id'] ?? 0) ?>">
                                    <input type="hidden" name="action" value="<?= $edit_size_variation ? 'update_size_variation' : 'add_size_variation' ?>">

                                    <div class="variation-form-grid">
                                        <div>
                                            <label class="form-label" for="sizeVariationInput">Size</label>
                                            <input
                                                type="text"
                                                class="form-control <?= isset($size_form_errors['size']) ? 'is-invalid' : '' ?>"
                                                id="sizeVariationInput"
                                                name="size"
                                                maxlength="20"
                                                placeholder="M"
                                                value="<?= htmlspecialchars($size_form_data['size']) ?>">
                                            <?php if (isset($size_form_errors['size'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($size_form_errors['size']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <label class="form-label" for="sizeVariationStockInput">Stock</label>
                                            <input
                                                type="number"
                                                min="0"
                                                step="1"
                                                class="form-control <?= isset($size_form_errors['stock_qty']) ? 'is-invalid' : '' ?>"
                                                id="sizeVariationStockInput"
                                                name="stock_qty"
                                                value="<?= htmlspecialchars($size_form_data['stock_qty']) ?>">
                                            <?php if (isset($size_form_errors['stock_qty'])): ?>
                                                <div class="invalid-feedback"><?= htmlspecialchars($size_form_errors['stock_qty']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="module-actions mt-3">
                                        <button type="submit" class="btn btn-light module-accent-btn">
                                            <i class="fas fa-floppy-disk"></i> <?= $edit_size_variation ? 'Update Size Variation' : 'Add Size Variation' ?>
                                        </button>
                                        <?php if ($edit_size_variation): ?>
                                            <a href="product_variations.php?id=<?= (int) $product_id ?>#size-variations" class="btn btn-outline-light">Cancel</a>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <?php if (!empty($size_variations)): ?>
                                    <div class="variation-table-wrap">
                                        <div class="table-responsive">
                                            <table class="table table-hover module-table table-stack">
                                                <thead>
                                                    <tr>
                                                        <th>Size</th>
                                                        <th>Stock</th>
                                                        <th class="text-end">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($size_variations as $variation): ?>
                                                        <tr>
                                                            <td data-label="Size">
                                                                <strong><?= htmlspecialchars($variation['size']) ?></strong>
                                                            </td>
                                                            <td data-label="Stock"><?= number_format((int) $variation['stock_qty']) ?> units</td>
                                                            <td data-label="Actions" class="actions-cell text-end">
                                                                <div class="module-actions justify-content-end">
                                                                    <a href="product_variations.php?id=<?= (int) $product_id ?>&edit_size=<?= (int) $variation['variation_id'] ?>#size-variations" class="btn btn-sm btn-outline-info">Edit</a>
                                                                    <form method="POST" action="product_variations.php?id=<?= (int) $product_id ?>#size-variations" class="module-inline-form" onsubmit="return confirm('Delete this size variation?');">
                                                                        <?= csrf_field() ?>
                                                                        <input type="hidden" name="action" value="delete_size_variation">
                                                                        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                                                        <input type="hidden" name="variation_id" value="<?= (int) $variation['variation_id'] ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="variation-empty-state">
                                        <i class="fas fa-ruler"></i>
                                        <p>No standalone size variations have been added yet.</p>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>

                    <section class="module-card mt-4" id="combination-variations">
                        <div class="module-card-header">
                            <div>
                                <h2>Color and Size Combination Management</h2>
                                <p class="module-card-subtitle mb-0">Use this for apparel that varies by both color and size, with stock tracked per combination.</p>
                            </div>
                        </div>

                        <?php if ($inline_messages['combination'] !== ''): ?>
                            <div class="alert alert-danger module-alert py-2" role="alert">
                                <?= htmlspecialchars($inline_messages['combination']) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="product_variations.php?id=<?= (int) $product_id ?>#combination-variations" class="module-form mb-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                            <input type="hidden" name="variation_id" value="<?= (int) ($edit_combo_variation['variation_id'] ?? 0) ?>">
                            <input type="hidden" name="action" value="<?= $edit_combo_variation ? 'update_combo_variation' : 'add_combo_variation' ?>">

                            <div class="variation-form-grid variation-form-grid-wide">
                                <div>
                                    <label class="form-label" for="comboColorInput">Color</label>
                                    <input
                                        type="text"
                                        class="form-control <?= isset($combination_form_errors['color']) ? 'is-invalid' : '' ?>"
                                        id="comboColorInput"
                                        name="color"
                                        maxlength="50"
                                        list="suggestedColorLabels"
                                        placeholder="Black"
                                        value="<?= htmlspecialchars($combination_form_data['color']) ?>">
                                    <?php if (isset($combination_form_errors['color'])): ?>
                                        <div class="invalid-feedback"><?= htmlspecialchars($combination_form_errors['color']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="form-label" for="comboSizeInput">Size</label>
                                    <input
                                        type="text"
                                        class="form-control <?= isset($combination_form_errors['size']) ? 'is-invalid' : '' ?>"
                                        id="comboSizeInput"
                                        name="size"
                                        maxlength="20"
                                        list="suggestedSizeLabels"
                                        placeholder="L"
                                        value="<?= htmlspecialchars($combination_form_data['size']) ?>">
                                    <?php if (isset($combination_form_errors['size'])): ?>
                                        <div class="invalid-feedback"><?= htmlspecialchars($combination_form_errors['size']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="form-label" for="comboStockInput">Stock</label>
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        class="form-control <?= isset($combination_form_errors['stock_qty']) ? 'is-invalid' : '' ?>"
                                        id="comboStockInput"
                                        name="stock_qty"
                                        value="<?= htmlspecialchars($combination_form_data['stock_qty']) ?>">
                                    <?php if (isset($combination_form_errors['stock_qty'])): ?>
                                        <div class="invalid-feedback"><?= htmlspecialchars($combination_form_errors['stock_qty']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <datalist id="suggestedColorLabels">
                                <?php foreach ($suggested_colors as $color_label): ?>
                                    <option value="<?= htmlspecialchars($color_label) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <datalist id="suggestedSizeLabels">
                                <?php foreach ($suggested_sizes as $size_label): ?>
                                    <option value="<?= htmlspecialchars($size_label) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>

                            <div class="module-actions mt-3">
                                <button type="submit" class="btn btn-light module-accent-btn">
                                    <i class="fas fa-floppy-disk"></i> <?= $edit_combo_variation ? 'Update Combination' : 'Add Combination' ?>
                                </button>
                                <?php if ($edit_combo_variation): ?>
                                    <a href="product_variations.php?id=<?= (int) $product_id ?>#combination-variations" class="btn btn-outline-light">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if (!empty($combination_variations)): ?>
                            <div class="variation-table-wrap">
                                <div class="table-responsive">
                                    <table class="table table-hover module-table table-stack">
                                        <thead>
                                            <tr>
                                                <th>Color</th>
                                                <th>Size</th>
                                                <th>Stock</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($combination_variations as $variation): ?>
                                                <tr>
                                                    <td data-label="Color"><strong><?= htmlspecialchars($variation['color']) ?></strong></td>
                                                    <td data-label="Size"><strong><?= htmlspecialchars($variation['size']) ?></strong></td>
                                                    <td data-label="Stock"><?= number_format((int) $variation['stock_qty']) ?> units</td>
                                                    <td data-label="Actions" class="actions-cell text-end">
                                                        <div class="module-actions justify-content-end">
                                                            <a href="product_variations.php?id=<?= (int) $product_id ?>&edit_combo=<?= (int) $variation['variation_id'] ?>#combination-variations" class="btn btn-sm btn-outline-info">Edit</a>
                                                            <form method="POST" action="product_variations.php?id=<?= (int) $product_id ?>#combination-variations" class="module-inline-form" onsubmit="return confirm('Delete this color and size combination?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_combo_variation">
                                                                <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                                                <input type="hidden" name="variation_id" value="<?= (int) $variation['variation_id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="variation-empty-state">
                                <i class="fas fa-layer-group"></i>
                                <p>No color and size combinations have been created yet.</p>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/product-management.js"></script>
</body>

</html>
