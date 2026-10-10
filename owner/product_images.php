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
    set_flash('danger', 'Please choose a valid product before managing images.');
    header('Location: products.php');
    exit();
}

$product_schema_issues = $shop_id > 0 ? product_module_schema_issues($conn) : ['Your account is not linked to a shop yet.'];
$image_schema_issues = product_module_image_schema_issues($conn);
$schema_issues = array_merge($product_schema_issues, $image_schema_issues);
$required_patches = [];
if (!empty($product_schema_issues)) {
    $required_patches[] = 'owner/db/product_management_patch.sql';
}
if (!empty($image_schema_issues)) {
    $required_patches[] = 'owner/db/product_image_management_patch.sql';
}

$product = empty($product_schema_issues) ? product_module_fetch_product($conn, $product_id, $shop_id) : null;
if (empty($product_schema_issues) && !$product) {
    set_flash('danger', 'The selected product could not be found for your shop.');
    header('Location: products.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security verification failed. Please try again.');
        header('Location: product_images.php?id=' . $product_id);
        exit();
    }

    if (!empty($schema_issues)) {
        set_flash('danger', 'The image module cannot run until the required schema patch has been applied.');
        header('Location: product_images.php?id=' . $product_id);
        exit();
    }

    $action = $_POST['action'] ?? '';
    $result = [
        'success' => false,
        'message' => 'The requested image action is not supported.'
    ];

    if ($action === 'upload_images') {
        $result = product_module_upload_product_images($conn, $product_id, $shop_id, $_FILES['product_images'] ?? []);
    } elseif ($action === 'replace_image') {
        $image_id = (int) ($_POST['image_id'] ?? 0);
        $result = product_module_replace_product_image($conn, $image_id, $product_id, $shop_id, $_FILES['replacement_image'] ?? []);
    } elseif ($action === 'delete_image') {
        $image_id = (int) ($_POST['image_id'] ?? 0);
        $result = product_module_delete_product_image($conn, $image_id, $product_id, $shop_id);
    } elseif ($action === 'set_main_image') {
        $image_id = (int) ($_POST['image_id'] ?? 0);
        $result = product_module_set_main_product_image($conn, $image_id, $product_id, $shop_id);
    }

    set_flash($result['success'] ? 'success' : 'danger', $result['message']);
    header('Location: product_images.php?id=' . $product_id);
    exit();
}

$product_images = empty($schema_issues) ? product_module_fetch_product_images($conn, $product_id, $shop_id) : [];
$main_image = product_module_get_main_image($product_images);
$image_count = count($product_images);
$remaining_slots = max(0, product_module_image_max_per_product() - $image_count);
$has_main_image = $main_image !== null;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Images | <?= htmlspecialchars($shop_name) ?></title>
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
                    <h1>Product Image Management</h1>
                    <p>Upload, replace, delete, and reorder product visuals for a single catalog item.</p>
                </div>

                <div class="module-backbar">
                    <div class="module-actions">
                        <a href="product_view.php?id=<?= (int) $product_id ?>" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left"></i> Back to Product
                        </a>
                        <a href="product_form.php?id=<?= (int) $product_id ?>" class="btn btn-outline-info">
                            <i class="fas fa-pen-to-square"></i> Edit Product
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
                        <div class="d-flex align-items-start gap-3">
                            <i class="fas fa-triangle-exclamation mt-1"></i>
                            <div>
                                <strong>Schema setup required.</strong>
                                <p class="mb-2">The product image module needs the product and image schema patches before it can safely store gallery data.</p>
                                <ul class="mb-2">
                                    <?php foreach ($schema_issues as $issue): ?>
                                        <li><?= htmlspecialchars($issue) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="mb-0">
                                    Apply
                                    <?php foreach ($required_patches as $index => $patch_file): ?>
                                        <?php if ($index > 0): ?> and <?php endif; ?>
                                        <code><?= htmlspecialchars($patch_file) ?></code>
                                    <?php endforeach; ?>
                                    first.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <section class="module-hero">
                        <div>
                            <h2><?= htmlspecialchars($product['product_name']) ?></h2>
                            <p><?= htmlspecialchars($product['description'] !== null && $product['description'] !== '' ? $product['description'] : 'No description has been added for this product yet.') ?></p>
                        </div>
                        <div class="module-hero-actions">
                            <span class="module-pill"><i class="fas fa-images"></i> <?= number_format($image_count) ?> image<?= $image_count === 1 ? '' : 's' ?></span>
                            <span class="module-pill"><i class="fas fa-star"></i> <?= $has_main_image ? 'Main image ready' : 'No main image yet' ?></span>
                        </div>
                    </section>

                    <section class="module-stats">
                        <article class="module-stat stat-primary">
                            <div class="module-stat-icon"><i class="fas fa-image"></i></div>
                            <div class="module-stat-value"><?= number_format($image_count) ?></div>
                            <div class="module-stat-label">Uploaded Images</div>
                        </article>
                        <article class="module-stat stat-success">
                            <div class="module-stat-icon"><i class="fas fa-star"></i></div>
                            <div class="module-stat-value"><?= $has_main_image ? 'Yes' : 'No' ?></div>
                            <div class="module-stat-label">Main Image Set</div>
                        </article>
                        <article class="module-stat stat-warning">
                            <div class="module-stat-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                            <div class="module-stat-value"><?= number_format($remaining_slots) ?></div>
                            <div class="module-stat-label">Remaining Slots</div>
                        </article>
                        <article class="module-stat stat-danger">
                            <div class="module-stat-icon"><i class="fas fa-ruler-combined"></i></div>
                            <div class="module-stat-value">5MB</div>
                            <div class="module-stat-label">Max Per Image</div>
                        </article>
                    </section>

                    <div class="row g-4">
                        <div class="col-xl-8">
                            <section class="module-card mb-4">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Main Product Image</h2>
                                        <p class="module-card-subtitle mb-0">This image should best represent the product in the catalog and gallery.</p>
                                    </div>
                                    <a href="products.php" class="btn btn-outline-light">Catalog List</a>
                                </div>

                                <?php if ($main_image): ?>
                                    <div class="gallery-main-preview">
                                        <img src="<?= htmlspecialchars($main_image['image_path']) ?>" alt="<?= htmlspecialchars($product['product_name']) ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="gallery-empty-state">
                                        <i class="fas fa-image"></i>
                                        <p>No product images have been uploaded yet.</p>
                                    </div>
                                <?php endif; ?>
                            </section>

                            <section class="module-card">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Product Image Gallery</h2>
                                        <p class="module-card-subtitle mb-0">Set the main image, replace visuals, or remove images you no longer need.</p>
                                    </div>
                                </div>

                                <?php if (!empty($product_images)): ?>
                                    <div class="gallery-grid">
                                        <?php foreach ($product_images as $image): ?>
                                            <?php $replace_preview_id = 'replace-preview-' . (int) $image['image_id']; ?>
                                            <article class="gallery-card">
                                                <div class="gallery-thumb">
                                                    <img src="<?= htmlspecialchars($image['image_path']) ?>" alt="<?= htmlspecialchars($product['product_name']) ?>">
                                                </div>

                                                <div class="gallery-card-body">
                                                    <div class="gallery-card-meta">
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <?php if (!empty($image['is_main'])): ?>
                                                                <span class="status-pill status-available">Main Image</span>
                                                            <?php else: ?>
                                                                <span class="status-pill status-info">Gallery Image</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="product-subline">
                                                            <?php if (!empty($image['created_at'])): ?>
                                                                <?= htmlspecialchars(date('d M Y', strtotime($image['created_at']))) ?>
                                                            <?php else: ?>
                                                                Legacy record
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>

                                                    <div class="module-actions mb-3">
                                                        <?php if (empty($image['is_main'])): ?>
                                                            <form method="POST" action="product_images.php?id=<?= (int) $product_id ?>" class="module-inline-form">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="set_main_image">
                                                                <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                                                <input type="hidden" name="image_id" value="<?= (int) $image['image_id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-light">Set Main</button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <form method="POST" action="product_images.php?id=<?= (int) $product_id ?>" class="module-inline-form" onsubmit="return confirm('Delete this product image?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_image">
                                                            <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                                            <input type="hidden" name="image_id" value="<?= (int) $image['image_id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                        </form>
                                                    </div>

                                                    <form method="POST" action="product_images.php?id=<?= (int) $product_id ?>" enctype="multipart/form-data" class="module-form">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="replace_image">
                                                        <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">
                                                        <input type="hidden" name="image_id" value="<?= (int) $image['image_id'] ?>">
                                                        <label class="form-label" for="replaceImage<?= (int) $image['image_id'] ?>">Replace Image</label>
                                                        <input
                                                            type="file"
                                                            class="form-control form-control-sm js-image-replace-input"
                                                            id="replaceImage<?= (int) $image['image_id'] ?>"
                                                            name="replacement_image"
                                                            accept=".jpg,.jpeg,.png,.webp,.gif,image/*"
                                                            data-preview-target="<?= htmlspecialchars($replace_preview_id) ?>"
                                                            required>
                                                        <div class="replace-preview" id="<?= htmlspecialchars($replace_preview_id) ?>"></div>
                                                        <button type="submit" class="btn btn-sm btn-light module-accent-btn mt-2">
                                                            <i class="fas fa-rotate"></i> Update Image
                                                        </button>
                                                    </form>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="gallery-empty-state">
                                        <i class="fas fa-images"></i>
                                        <p>No gallery images available yet. Upload the first set below.</p>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>

                        <div class="col-xl-4">
                            <section class="module-card mb-4">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Upload Images</h2>
                                        <p class="module-card-subtitle mb-0">Add multiple product visuals in one step.</p>
                                    </div>
                                </div>

                                <form method="POST" action="product_images.php?id=<?= (int) $product_id ?>" enctype="multipart/form-data" class="module-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="upload_images">
                                    <input type="hidden" name="product_id" value="<?= (int) $product_id ?>">

                                    <div class="mb-3">
                                        <label class="form-label" for="galleryUploadInput">Choose Product Images</label>
                                        <input
                                            type="file"
                                            class="form-control"
                                            id="galleryUploadInput"
                                            name="product_images[]"
                                            accept=".jpg,.jpeg,.png,.webp,.gif,image/*"
                                            multiple>
                                        <div class="form-text">Upload up to <?= number_format(product_module_image_max_per_product()) ?> images per product. JPG, PNG, WEBP, or GIF only.</div>
                                    </div>

                                    <div class="upload-preview-grid" id="galleryUploadPreview">
                                        <div class="upload-preview-empty">Selected files will preview here before upload.</div>
                                    </div>

                                    <button type="submit" class="btn btn-light module-accent-btn mt-3" <?= $remaining_slots <= 0 ? 'disabled' : '' ?>>
                                        <i class="fas fa-cloud-arrow-up"></i> Upload Images
                                    </button>
                                </form>
                            </section>

                            <section class="module-card">
                                <div class="module-card-header">
                                    <div>
                                        <h2>Upload Rules</h2>
                                        <p class="module-card-subtitle mb-0">Keep the gallery clean and consistent.</p>
                                    </div>
                                </div>

                                <ul class="module-meta-list">
                                    <li>
                                        <span class="module-meta-label">Allowed Types</span>
                                        <span class="module-meta-value">JPG, PNG, WEBP, GIF</span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Max File Size</span>
                                        <span class="module-meta-value">5MB per image</span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Image Limit</span>
                                        <span class="module-meta-value"><?= number_format(product_module_image_max_per_product()) ?> images per product</span>
                                    </li>
                                    <li>
                                        <span class="module-meta-label">Main Image</span>
                                        <span class="module-meta-value">Only one image can be marked as main</span>
                                    </li>
                                </ul>

                                <div class="module-note mt-3">
                                    The first uploaded image becomes the main product image automatically when no main image exists yet. You can reassign it at any time from the gallery.
                                </div>
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
