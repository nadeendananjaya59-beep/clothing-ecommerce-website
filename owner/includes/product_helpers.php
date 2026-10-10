<?php
/**
 * Product Management helper functions.
 * Procedural helpers shared by the owner product module pages.
 */

function product_module_allowed_lookup_tables() {
    return [
        'categories' => [
            'id' => 'category_id',
            'label' => 'category_name',
            'has_status' => true
        ],
        'brands' => [
            'id' => 'brand_id',
            'label' => 'brand_name',
            'has_status' => true
        ],
        'suppliers' => [
            'id' => 'supplier_id',
            'label' => 'supplier_name',
            'has_status' => false
        ]
    ];
}

function product_module_column_exists($conn, $table, $column) {
    static $cache = [];

    $allowed_tables = ['products', 'categories', 'brands', 'suppliers', 'product_images', 'product_variations', 'orders', 'order_items'];
    if (!in_array($table, $allowed_tables, true)) {
        return false;
    }

    $cache_key = $table . '.' . $column;
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    $column_safe = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column_safe'");
    $cache[$cache_key] = $result && mysqli_num_rows($result) > 0;

    if ($result) {
        mysqli_free_result($result);
    }

    return $cache[$cache_key];
}

function product_module_schema_issues($conn) {
    $issues = [];

    foreach (['products', 'categories', 'brands', 'suppliers'] as $table) {
        if (!product_module_column_exists($conn, $table, 'shop_id')) {
            $issues[] = "Missing required `shop_id` column on the `$table` table.";
        }
    }

    return $issues;
}

function product_module_image_schema_issues($conn) {
    $issues = [];

    foreach (['product_id', 'image_path', 'is_main'] as $column) {
        if (!product_module_column_exists($conn, 'product_images', $column)) {
            $issues[] = "Missing required `$column` column on the `product_images` table.";
        }
    }

    return $issues;
}

function product_module_image_features_ready($conn) {
    return empty(product_module_image_schema_issues($conn));
}

function product_module_variation_schema_issues($conn) {
    $issues = [];

    foreach (['variation_id', 'product_id', 'color', 'size', 'stock_qty'] as $column) {
        if (!product_module_column_exists($conn, 'product_variations', $column)) {
            $issues[] = "Missing required `$column` column on the `product_variations` table.";
        }
    }

    return $issues;
}

function product_module_variation_features_ready($conn) {
    return empty(product_module_variation_schema_issues($conn));
}

function product_module_performance_schema_issues($conn) {
    $issues = [];
    $required_columns = [
        'products' => ['product_id', 'shop_id', 'product_name'],
        'orders' => ['order_id', 'shop_id', 'order_date', 'order_status'],
        'order_items' => ['order_id', 'product_id', 'quantity']
    ];

    foreach ($required_columns as $table => $columns) {
        foreach ($columns as $column) {
            if (!product_module_column_exists($conn, $table, $column)) {
                $issues[] = "Missing required `$column` column on the `$table` table.";
            }
        }
    }

    if (
        !product_module_column_exists($conn, 'order_items', 'subtotal') &&
        !product_module_column_exists($conn, 'order_items', 'unit_price')
    ) {
        $issues[] = 'The `order_items` table must include either a `subtotal` or `unit_price` column for revenue analysis.';
    }

    return $issues;
}

function product_module_owner_initials($owner_name) {
    $owner_name = trim((string) $owner_name);
    if ($owner_name === '') {
        return 'OW';
    }

    $parts = preg_split('/\s+/', $owner_name);
    $first = strtoupper(substr($parts[0], 0, 1));
    $second = isset($parts[1]) ? strtoupper(substr($parts[1], 0, 1)) : strtoupper(substr($parts[0], 1, 1));

    return trim($first . $second) !== '' ? trim($first . $second) : 'OW';
}

function product_module_default_form_data() {
    return [
        'product_name' => '',
        'description' => '',
        'category_id' => '',
        'brand_id' => '',
        'supplier_id' => '',
        'cost_price' => '',
        'selling_price' => '',
        'discount_price' => '',
        'stock_qty' => '0',
        'reorder_level' => '10',
        'weight' => '',
        'status' => 'Available'
    ];
}

function product_module_fill_form_data($product = []) {
    $data = product_module_default_form_data();

    foreach ($data as $field => $default_value) {
        if (isset($product[$field]) && $product[$field] !== null) {
            $data[$field] = (string) $product[$field];
        }
    }

    return $data;
}

function product_module_image_max_per_product() {
    return 12;
}

function product_module_image_max_file_size() {
    return 5 * 1024 * 1024;
}

function product_module_image_allowed_mimes() {
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
}

function product_module_variation_type_labels() {
    return [
        'color' => 'Color Variation',
        'size' => 'Size Variation',
        'combination' => 'Color & Size Combination'
    ];
}

function product_module_ref_values(&$values) {
    $refs = [];
    foreach ($values as $key => $value) {
        $refs[$key] = &$values[$key];
    }
    return $refs;
}

function product_module_bind_params($stmt, &$params) {
    if (empty($params)) {
        return true;
    }

    $types = str_repeat('s', count($params));
    $bind_args = [$stmt, $types];
    foreach ($params as $key => $value) {
        $bind_args[] = &$params[$key];
    }

    return call_user_func_array('mysqli_stmt_bind_param', $bind_args);
}

function product_module_prepare_and_execute($conn, $sql, $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }

    if (!product_module_bind_params($stmt, $params)) {
        mysqli_stmt_close($stmt);
        return false;
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }

    return $stmt;
}

function product_module_fetch_all($result) {
    $rows = [];
    if (!$result) {
        return $rows;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;
}

function product_module_fetch_lookup_options($conn, $table, $shop_id) {
    $lookup_tables = product_module_allowed_lookup_tables();
    if (!isset($lookup_tables[$table])) {
        return [];
    }

    $config = $lookup_tables[$table];
    $conditions = [];
    $params = [];

    if ($config['has_status'] && product_module_column_exists($conn, $table, 'status')) {
        $conditions[] = "status = 'Active'";
    }

    if (product_module_column_exists($conn, $table, 'shop_id')) {
        $conditions[] = "(shop_id = ? OR shop_id IS NULL)";
        $params[] = (string) $shop_id;
    }

    $sql = "SELECT {$config['id']} AS item_id, {$config['label']} AS item_name
            FROM `$table`";

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY item_name ASC';

    $stmt = product_module_prepare_and_execute($conn, $sql, $params);
    if (!$stmt) {
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = product_module_fetch_all($result);
    mysqli_stmt_close($stmt);

    return $rows;
}

function product_module_lookup_exists($conn, $table, $lookup_id, $shop_id) {
    if ((int) $lookup_id <= 0) {
        return false;
    }

    $lookup_tables = product_module_allowed_lookup_tables();
    if (!isset($lookup_tables[$table])) {
        return false;
    }

    $config = $lookup_tables[$table];
    $params = [(string) $lookup_id];
    $conditions = ["{$config['id']} = ?"];

    if ($config['has_status'] && product_module_column_exists($conn, $table, 'status')) {
        $conditions[] = "status = 'Active'";
    }

    if (product_module_column_exists($conn, $table, 'shop_id')) {
        $conditions[] = "(shop_id = ? OR shop_id IS NULL)";
        $params[] = (string) $shop_id;
    }

    $sql = "SELECT {$config['id']}
            FROM `$table`
            WHERE " . implode(' AND ', $conditions) . "
            LIMIT 1";

    $stmt = product_module_prepare_and_execute($conn, $sql, $params);
    if (!$stmt) {
        return false;
    }

    $result = mysqli_stmt_get_result($stmt);
    $found = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    return $found;
}

function product_module_safe_strlen($value) {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function product_module_validate_product($conn, $input, $shop_id) {
    $data = product_module_default_form_data();
    $errors = [];

    foreach ($data as $field => $default_value) {
        $data[$field] = trim((string) ($input[$field] ?? $default_value));
    }

    if ($data['product_name'] === '') {
        $errors['product_name'] = 'Product name is required.';
    } elseif (product_module_safe_strlen($data['product_name']) > 150) {
        $errors['product_name'] = 'Product name must be 150 characters or fewer.';
    }

    if ($data['description'] !== '' && product_module_safe_strlen($data['description']) > 5000) {
        $errors['description'] = 'Description is too long.';
    }

    $required_numbers = [
        'selling_price' => 'Selling price',
        'stock_qty' => 'Stock quantity',
        'reorder_level' => 'Reorder level'
    ];

    foreach ($required_numbers as $field => $label) {
        if ($data[$field] === '') {
            $errors[$field] = $label . ' is required.';
            continue;
        }

        if (in_array($field, ['stock_qty', 'reorder_level'], true)) {
            if (filter_var($data[$field], FILTER_VALIDATE_INT) === false || (int) $data[$field] < 0) {
                $errors[$field] = $label . ' must be a non-negative whole number.';
            } else {
                $data[$field] = (string) ((int) $data[$field]);
            }
        } else {
            if (!is_numeric($data[$field]) || (float) $data[$field] < 0) {
                $errors[$field] = $label . ' must be a valid amount.';
            } else {
                $data[$field] = number_format((float) $data[$field], 2, '.', '');
            }
        }
    }

    $optional_numbers = [
        'cost_price' => 'Cost price',
        'discount_price' => 'Discount price',
        'weight' => 'Weight'
    ];

    foreach ($optional_numbers as $field => $label) {
        if ($data[$field] === '') {
            continue;
        }

        if (!is_numeric($data[$field]) || (float) $data[$field] < 0) {
            $errors[$field] = $label . ' must be a valid amount.';
            continue;
        }

        $data[$field] = number_format((float) $data[$field], 2, '.', '');
    }

    if ($data['discount_price'] !== '' && $data['selling_price'] !== '' && (float) $data['discount_price'] > (float) $data['selling_price']) {
        $errors['discount_price'] = 'Discount price cannot be greater than the selling price.';
    }

    $lookup_fields = [
        'category_id' => 'categories',
        'brand_id' => 'brands',
        'supplier_id' => 'suppliers'
    ];

    foreach ($lookup_fields as $field => $table) {
        if ($data[$field] === '') {
            continue;
        }

        if (filter_var($data[$field], FILTER_VALIDATE_INT) === false || (int) $data[$field] <= 0) {
            $errors[$field] = 'Please select a valid option.';
            continue;
        }

        if (!product_module_lookup_exists($conn, $table, (int) $data[$field], $shop_id)) {
            $errors[$field] = 'The selected option is not available for your shop.';
        } else {
            $data[$field] = (string) ((int) $data[$field]);
        }
    }

    $allowed_statuses = ['Available', 'Out of Stock'];
    if (!in_array($data['status'], $allowed_statuses, true)) {
        $data['status'] = 'Available';
    }

    if ($data['stock_qty'] === '0') {
        $data['status'] = 'Out of Stock';
    }

    return [$data, $errors];
}

function product_module_fetch_product($conn, $product_id, $shop_id) {
    $sql = "SELECT p.*,
                   c.category_name,
                   b.brand_name,
                   s.supplier_name,
                   s.contact_no AS supplier_contact,
                   s.email AS supplier_email,
                   s.address AS supplier_address
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN brands b ON p.brand_id = b.brand_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = ? AND p.shop_id = ?
            LIMIT 1";

    $params = [(string) $product_id, (string) $shop_id];
    $stmt = product_module_prepare_and_execute($conn, $sql, $params);
    if (!$stmt) {
        return null;
    }

    $result = mysqli_stmt_get_result($stmt);
    $product = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return $product ?: null;
}

function product_module_fetch_product_image_count($conn, $product_id, $shop_id) {
    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT COUNT(*) AS total_images
         FROM product_images pi
         INNER JOIN products p ON p.product_id = pi.product_id
         WHERE pi.product_id = ? AND p.shop_id = ?",
        [(string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        return 0;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return (int) ($row['total_images'] ?? 0);
}

function product_module_fetch_product_images($conn, $product_id, $shop_id) {
    $has_is_main = product_module_column_exists($conn, 'product_images', 'is_main');
    $has_created_at = product_module_column_exists($conn, 'product_images', 'created_at');
    $has_updated_at = product_module_column_exists($conn, 'product_images', 'updated_at');

    $select_is_main = $has_is_main ? 'pi.is_main' : '0 AS is_main';
    $select_created_at = $has_created_at ? 'pi.created_at' : 'NULL AS created_at';
    $select_updated_at = $has_updated_at ? 'pi.updated_at' : 'NULL AS updated_at';
    $order_by = $has_is_main ? 'pi.is_main DESC, pi.image_id ASC' : 'pi.image_id ASC';

    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT pi.image_id,
                pi.product_id,
                pi.image_path,
                $select_is_main,
                $select_created_at,
                $select_updated_at
         FROM product_images pi
         INNER JOIN products p ON p.product_id = pi.product_id
         WHERE pi.product_id = ? AND p.shop_id = ?
         ORDER BY $order_by",
        [(string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = product_module_fetch_all($result);
    mysqli_stmt_close($stmt);

    return $rows;
}

function product_module_fetch_product_image($conn, $image_id, $product_id, $shop_id) {
    $images = product_module_fetch_product_images($conn, $product_id, $shop_id);
    foreach ($images as $image) {
        if ((int) $image['image_id'] === (int) $image_id) {
            return $image;
        }
    }

    return null;
}

function product_module_get_main_image($images) {
    if (empty($images)) {
        return null;
    }

    foreach ($images as $image) {
        if (!empty($image['is_main'])) {
            return $image;
        }
    }

    return $images[0];
}

function product_module_upload_root_path() {
    return dirname(__DIR__) . '/uploads/product-images';
}

function product_module_product_upload_directory($shop_id, $product_id) {
    return product_module_upload_root_path() . '/shop-' . (int) $shop_id . '/product-' . (int) $product_id;
}

function product_module_product_upload_web_directory($shop_id, $product_id) {
    return 'uploads/product-images/shop-' . (int) $shop_id . '/product-' . (int) $product_id;
}

function product_module_ensure_directory($directory) {
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0775, true);
}

function product_module_normalize_uploaded_files($files) {
    $normalized = [];
    if (!isset($files['name'])) {
        return $normalized;
    }

    if (is_array($files['name'])) {
        $file_count = count($files['name']);
        for ($index = 0; $index < $file_count; $index++) {
            $normalized[] = [
                'name' => $files['name'][$index] ?? '',
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0
            ];
        }
    } else {
        $normalized[] = $files;
    }

    return $normalized;
}

function product_module_validate_image_upload($file, $is_required = true) {
    $error_code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error_code === UPLOAD_ERR_NO_FILE) {
        return [
            'valid' => !$is_required,
            'message' => $is_required ? 'Please choose an image to upload.' : '',
            'extension' => null,
            'mime' => null
        ];
    }

    if ($error_code !== UPLOAD_ERR_OK) {
        return [
            'valid' => false,
            'message' => 'The image upload failed. Please try again.',
            'extension' => null,
            'mime' => null
        ];
    }

    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > product_module_image_max_file_size()) {
        return [
            'valid' => false,
            'message' => 'Each image must be 5MB or smaller.',
            'extension' => null,
            'mime' => null
        ];
    }

    $image_info = @getimagesize($file['tmp_name']);
    if (!$image_info || empty($image_info['mime'])) {
        return [
            'valid' => false,
            'message' => 'Only valid image files can be uploaded.',
            'extension' => null,
            'mime' => null
        ];
    }

    $allowed_mimes = product_module_image_allowed_mimes();
    $mime = $image_info['mime'];
    if (!isset($allowed_mimes[$mime])) {
        return [
            'valid' => false,
            'message' => 'Allowed image types are JPG, PNG, WEBP, and GIF.',
            'extension' => null,
            'mime' => null
        ];
    }

    return [
        'valid' => true,
        'message' => '',
        'extension' => $allowed_mimes[$mime],
        'mime' => $mime
    ];
}

function product_module_remove_image_file($relative_path) {
    $relative_path = str_replace(['\\', '..'], ['/', ''], (string) $relative_path);
    if ($relative_path === '') {
        return;
    }

    $absolute_path = dirname(__DIR__) . '/' . ltrim($relative_path, '/');
    if (is_file($absolute_path)) {
        @unlink($absolute_path);
    }
}

function product_module_cleanup_empty_image_directories($shop_id, $product_id) {
    $product_dir = product_module_product_upload_directory($shop_id, $product_id);
    $shop_dir = dirname($product_dir);

    if (is_dir($product_dir)) {
        $items = scandir($product_dir);
        if ($items && count($items) <= 2) {
            @rmdir($product_dir);
        }
    }

    if (is_dir($shop_dir)) {
        $items = scandir($shop_dir);
        if ($items && count($items) <= 2) {
            @rmdir($shop_dir);
        }
    }
}

function product_module_store_uploaded_image($file, $shop_id, $product_id, $extension) {
    $absolute_directory = product_module_product_upload_directory($shop_id, $product_id);
    $web_directory = product_module_product_upload_web_directory($shop_id, $product_id);

    if (!product_module_ensure_directory($absolute_directory)) {
        return [
            'success' => false,
            'message' => 'Unable to prepare the upload directory for product images.'
        ];
    }

    $file_name = 'img_' . (int) $product_id . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $absolute_path = $absolute_directory . '/' . $file_name;
    $relative_path = $web_directory . '/' . $file_name;

    if (!move_uploaded_file($file['tmp_name'], $absolute_path)) {
        return [
            'success' => false,
            'message' => 'Unable to move the uploaded image into storage.'
        ];
    }

    return [
        'success' => true,
        'absolute_path' => $absolute_path,
        'relative_path' => $relative_path
    ];
}

function product_module_upload_product_images($conn, $product_id, $shop_id, $files) {
    if (!product_module_image_features_ready($conn)) {
        return [
            'success' => false,
            'message' => 'The product image schema patch is required before uploads can be used.'
        ];
    }

    $product = product_module_fetch_product($conn, $product_id, $shop_id);
    if (!$product) {
        return [
            'success' => false,
            'message' => 'The selected product could not be found.'
        ];
    }

    $normalized_files = array_values(array_filter(
        product_module_normalize_uploaded_files($files),
        function ($file) {
            return ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        }
    ));

    if (empty($normalized_files)) {
        return [
            'success' => false,
            'message' => 'Please choose at least one image to upload.'
        ];
    }

    $existing_images = product_module_fetch_product_images($conn, $product_id, $shop_id);
    $existing_count = count($existing_images);
    $incoming_count = count($normalized_files);

    if ($existing_count + $incoming_count > product_module_image_max_per_product()) {
        return [
            'success' => false,
            'message' => 'A product can have up to ' . product_module_image_max_per_product() . ' images.'
        ];
    }

    $stored_files = [];
    foreach ($normalized_files as $file) {
        $validation = product_module_validate_image_upload($file, true);
        if (!$validation['valid']) {
            foreach ($stored_files as $stored_file) {
                if (!empty($stored_file['relative_path'])) {
                    product_module_remove_image_file($stored_file['relative_path']);
                }
            }

            return [
                'success' => false,
                'message' => $validation['message']
            ];
        }

        $storage = product_module_store_uploaded_image($file, $shop_id, $product_id, $validation['extension']);
        if (!$storage['success']) {
            foreach ($stored_files as $stored_file) {
                if (!empty($stored_file['relative_path'])) {
                    product_module_remove_image_file($stored_file['relative_path']);
                }
            }

            return $storage;
        }

        $stored_files[] = $storage;
    }

    $has_main_image = false;
    foreach ($existing_images as $image) {
        if (!empty($image['is_main'])) {
            $has_main_image = true;
            break;
        }
    }

    mysqli_begin_transaction($conn);

    if (!$has_main_image && $existing_count > 0) {
        $reset_stmt = product_module_prepare_and_execute(
            $conn,
            "UPDATE product_images SET is_main = 0 WHERE product_id = ?",
            [(string) $product_id]
        );

        if (!$reset_stmt) {
            mysqli_rollback($conn);
            foreach ($stored_files as $stored_file) {
                product_module_remove_image_file($stored_file['relative_path']);
            }

            return [
                'success' => false,
                'message' => 'Unable to prepare main image settings for the uploaded images.'
            ];
        }

        mysqli_stmt_close($reset_stmt);
    }

    foreach ($stored_files as $index => $stored_file) {
        $is_main = (!$has_main_image && $index === 0) || ($existing_count === 0 && $index === 0) ? '1' : '0';
        $insert_stmt = product_module_prepare_and_execute(
            $conn,
            "INSERT INTO product_images (product_id, image_path, is_main, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())",
            [(string) $product_id, $stored_file['relative_path'], $is_main]
        );

        if (!$insert_stmt) {
            mysqli_rollback($conn);
            foreach ($stored_files as $cleanup_file) {
                product_module_remove_image_file($cleanup_file['relative_path']);
            }

            return [
                'success' => false,
                'message' => 'Unable to save the uploaded image records.'
            ];
        }

        mysqli_stmt_close($insert_stmt);
    }

    mysqli_commit($conn);

    return [
        'success' => true,
        'message' => $incoming_count . ' product image' . ($incoming_count === 1 ? ' was' : 's were') . ' uploaded successfully.'
    ];
}

function product_module_replace_product_image($conn, $image_id, $product_id, $shop_id, $file) {
    if (!product_module_image_features_ready($conn)) {
        return [
            'success' => false,
            'message' => 'The product image schema patch is required before image replacement can be used.'
        ];
    }

    $image = product_module_fetch_product_image($conn, $image_id, $product_id, $shop_id);
    if (!$image) {
        return [
            'success' => false,
            'message' => 'The selected product image could not be found.'
        ];
    }

    $validation = product_module_validate_image_upload($file, true);
    if (!$validation['valid']) {
        return [
            'success' => false,
            'message' => $validation['message']
        ];
    }

    $storage = product_module_store_uploaded_image($file, $shop_id, $product_id, $validation['extension']);
    if (!$storage['success']) {
        return $storage;
    }

    mysqli_begin_transaction($conn);
    $stmt = product_module_prepare_and_execute(
        $conn,
        "UPDATE product_images
         SET image_path = ?, updated_at = NOW()
         WHERE image_id = ? AND product_id = ?",
        [$storage['relative_path'], (string) $image_id, (string) $product_id]
    );

    if (!$stmt) {
        mysqli_rollback($conn);
        product_module_remove_image_file($storage['relative_path']);

        return [
            'success' => false,
            'message' => 'Unable to update the selected image.'
        ];
    }

    mysqli_stmt_close($stmt);
    mysqli_commit($conn);
    product_module_remove_image_file($image['image_path']);

    return [
        'success' => true,
        'message' => 'Product image updated successfully.'
    ];
}

function product_module_set_main_product_image($conn, $image_id, $product_id, $shop_id) {
    if (!product_module_image_features_ready($conn)) {
        return [
            'success' => false,
            'message' => 'The product image schema patch is required before main image selection can be used.'
        ];
    }

    $image = product_module_fetch_product_image($conn, $image_id, $product_id, $shop_id);
    if (!$image) {
        return [
            'success' => false,
            'message' => 'The selected product image could not be found.'
        ];
    }

    mysqli_begin_transaction($conn);

    $reset_stmt = product_module_prepare_and_execute(
        $conn,
        "UPDATE product_images SET is_main = 0 WHERE product_id = ?",
        [(string) $product_id]
    );

    if (!$reset_stmt) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'Unable to clear the current main image.'
        ];
    }
    mysqli_stmt_close($reset_stmt);

    $main_stmt = product_module_prepare_and_execute(
        $conn,
        "UPDATE product_images SET is_main = 1, updated_at = NOW() WHERE image_id = ? AND product_id = ?",
        [(string) $image_id, (string) $product_id]
    );

    if (!$main_stmt) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'Unable to set the selected image as the main image.'
        ];
    }
    mysqli_stmt_close($main_stmt);

    mysqli_commit($conn);

    return [
        'success' => true,
        'message' => 'Main product image updated successfully.'
    ];
}

function product_module_delete_product_image($conn, $image_id, $product_id, $shop_id) {
    if (!product_module_image_features_ready($conn)) {
        return [
            'success' => false,
            'message' => 'The product image schema patch is required before image deletion can be used.'
        ];
    }

    $image = product_module_fetch_product_image($conn, $image_id, $product_id, $shop_id);
    if (!$image) {
        return [
            'success' => false,
            'message' => 'The selected product image could not be found.'
        ];
    }

    $all_images = product_module_fetch_product_images($conn, $product_id, $shop_id);

    mysqli_begin_transaction($conn);

    $delete_stmt = product_module_prepare_and_execute(
        $conn,
        "DELETE FROM product_images WHERE image_id = ? AND product_id = ?",
        [(string) $image_id, (string) $product_id]
    );

    if (!$delete_stmt) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'Unable to delete the selected product image.'
        ];
    }
    mysqli_stmt_close($delete_stmt);

    if (!empty($image['is_main'])) {
        $replacement_image = null;
        foreach ($all_images as $candidate) {
            if ((int) $candidate['image_id'] !== (int) $image_id) {
                $replacement_image = $candidate;
                break;
            }
        }

        if ($replacement_image) {
            $reset_stmt = product_module_prepare_and_execute(
                $conn,
                "UPDATE product_images SET is_main = 0 WHERE product_id = ?",
                [(string) $product_id]
            );

            if (!$reset_stmt) {
                mysqli_rollback($conn);
                return [
                    'success' => false,
                    'message' => 'Unable to reassign the main product image.'
                ];
            }
            mysqli_stmt_close($reset_stmt);

            $main_stmt = product_module_prepare_and_execute(
                $conn,
                "UPDATE product_images SET is_main = 1, updated_at = NOW() WHERE image_id = ?",
                [(string) $replacement_image['image_id']]
            );

            if (!$main_stmt) {
                mysqli_rollback($conn);
                return [
                    'success' => false,
                    'message' => 'Unable to reassign the main product image.'
                ];
            }
            mysqli_stmt_close($main_stmt);
        }
    }

    mysqli_commit($conn);
    product_module_remove_image_file($image['image_path']);
    product_module_cleanup_empty_image_directories($shop_id, $product_id);

    return [
        'success' => true,
        'message' => 'Product image deleted successfully.'
    ];
}

function product_module_normalize_variation_value($value) {
    return trim((string) $value);
}

function product_module_validate_variation_input($input, $variation_type) {
    $data = [
        'color' => product_module_normalize_variation_value($input['color'] ?? ''),
        'size' => product_module_normalize_variation_value($input['size'] ?? ''),
        'stock_qty' => trim((string) ($input['stock_qty'] ?? '0'))
    ];
    $errors = [];

    if ($variation_type === 'color') {
        if ($data['color'] === '') {
            $errors['color'] = 'Color is required for a color variation.';
        }
        $data['size'] = '';
    } elseif ($variation_type === 'size') {
        if ($data['size'] === '') {
            $errors['size'] = 'Size is required for a size variation.';
        }
        $data['color'] = '';
    } else {
        if ($data['color'] === '') {
            $errors['color'] = 'Color is required for a color and size combination.';
        }
        if ($data['size'] === '') {
            $errors['size'] = 'Size is required for a color and size combination.';
        }
    }

    if ($data['color'] !== '' && product_module_safe_strlen($data['color']) > 50) {
        $errors['color'] = 'Color must be 50 characters or fewer.';
    }

    if ($data['size'] !== '' && product_module_safe_strlen($data['size']) > 20) {
        $errors['size'] = 'Size must be 20 characters or fewer.';
    }

    if ($data['stock_qty'] === '') {
        $errors['stock_qty'] = 'Variation stock is required.';
    } elseif (filter_var($data['stock_qty'], FILTER_VALIDATE_INT) === false || (int) $data['stock_qty'] < 0) {
        $errors['stock_qty'] = 'Variation stock must be a non-negative whole number.';
    } else {
        $data['stock_qty'] = (string) ((int) $data['stock_qty']);
    }

    if ($data['color'] === '' && $data['size'] === '') {
        $errors['variation'] = 'At least one variation attribute is required.';
    }

    return [$data, $errors];
}

function product_module_fetch_product_variation($conn, $variation_id, $product_id, $shop_id) {
    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT pv.variation_id,
                pv.product_id,
                COALESCE(pv.color, '') AS color,
                COALESCE(pv.size, '') AS size,
                pv.stock_qty
         FROM product_variations pv
         INNER JOIN products p ON p.product_id = pv.product_id
         WHERE pv.variation_id = ? AND pv.product_id = ? AND p.shop_id = ?
         LIMIT 1",
        [(string) $variation_id, (string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        return null;
    }

    $result = mysqli_stmt_get_result($stmt);
    $variation = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return $variation ?: null;
}

function product_module_variation_matches_type($variation, $variation_type) {
    if (!$variation) {
        return false;
    }

    $color = trim((string) ($variation['color'] ?? ''));
    $size = trim((string) ($variation['size'] ?? ''));

    if ($variation_type === 'color') {
        return $color !== '' && $size === '';
    }

    if ($variation_type === 'size') {
        return $color === '' && $size !== '';
    }

    return $color !== '' && $size !== '';
}

function product_module_fetch_product_variations($conn, $product_id, $shop_id, $variation_type = 'all') {
    $conditions = [];

    if ($variation_type === 'color') {
        $conditions[] = "COALESCE(pv.color, '') <> ''";
        $conditions[] = "COALESCE(pv.size, '') = ''";
    } elseif ($variation_type === 'size') {
        $conditions[] = "COALESCE(pv.color, '') = ''";
        $conditions[] = "COALESCE(pv.size, '') <> ''";
    } elseif ($variation_type === 'combination') {
        $conditions[] = "COALESCE(pv.color, '') <> ''";
        $conditions[] = "COALESCE(pv.size, '') <> ''";
    } else {
        $conditions[] = "(COALESCE(pv.color, '') <> '' OR COALESCE(pv.size, '') <> '')";
    }

    $sql = "SELECT pv.variation_id,
                   pv.product_id,
                   COALESCE(pv.color, '') AS color,
                   COALESCE(pv.size, '') AS size,
                   pv.stock_qty
            FROM product_variations pv
            INNER JOIN products p ON p.product_id = pv.product_id
            WHERE pv.product_id = ? AND p.shop_id = ? AND " . implode(' AND ', $conditions) . "
            ORDER BY COALESCE(pv.color, '') ASC, COALESCE(pv.size, '') ASC, pv.variation_id ASC";

    $stmt = product_module_prepare_and_execute(
        $conn,
        $sql,
        [(string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $rows = product_module_fetch_all($result);
    mysqli_stmt_close($stmt);

    return $rows;
}

function product_module_fetch_variation_summary($conn, $product_id, $shop_id) {
    $summary = [
        'total_variations' => 0,
        'color_variations' => 0,
        'size_variations' => 0,
        'combination_variations' => 0,
        'total_variation_stock' => 0,
        'unique_colors' => 0,
        'unique_sizes' => 0
    ];

    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT COUNT(*) AS total_variations,
                SUM(CASE WHEN COALESCE(pv.color, '') <> '' AND COALESCE(pv.size, '') = '' THEN 1 ELSE 0 END) AS color_variations,
                SUM(CASE WHEN COALESCE(pv.color, '') = '' AND COALESCE(pv.size, '') <> '' THEN 1 ELSE 0 END) AS size_variations,
                SUM(CASE WHEN COALESCE(pv.color, '') <> '' AND COALESCE(pv.size, '') <> '' THEN 1 ELSE 0 END) AS combination_variations,
                COALESCE(SUM(pv.stock_qty), 0) AS total_variation_stock,
                COUNT(DISTINCT CASE WHEN COALESCE(pv.color, '') <> '' THEN pv.color END) AS unique_colors,
                COUNT(DISTINCT CASE WHEN COALESCE(pv.size, '') <> '' THEN pv.size END) AS unique_sizes
         FROM product_variations pv
         INNER JOIN products p ON p.product_id = pv.product_id
         WHERE pv.product_id = ? AND p.shop_id = ?",
        [(string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        return $summary;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($row) {
        $summary = array_merge($summary, $row);
    }

    return $summary;
}

function product_module_fetch_variation_labels($conn, $product_id, $shop_id, $field) {
    if (!in_array($field, ['color', 'size'], true)) {
        return [];
    }

    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT DISTINCT COALESCE(pv.`$field`, '') AS variation_label
         FROM product_variations pv
         INNER JOIN products p ON p.product_id = pv.product_id
         WHERE pv.product_id = ? AND p.shop_id = ? AND COALESCE(pv.`$field`, '') <> ''
         ORDER BY variation_label ASC",
        [(string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $labels = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $labels[] = $row['variation_label'];
        }
    }
    mysqli_stmt_close($stmt);

    return $labels;
}

function product_module_variation_duplicate_exists($conn, $product_id, $shop_id, $color, $size, $exclude_variation_id = 0) {
    $params = [(string) $product_id, (string) $shop_id, $color, $size];
    $exclude_sql = '';

    if ((int) $exclude_variation_id > 0) {
        $exclude_sql = ' AND pv.variation_id <> ?';
        $params[] = (string) $exclude_variation_id;
    }

    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT pv.variation_id
         FROM product_variations pv
         INNER JOIN products p ON p.product_id = pv.product_id
         WHERE pv.product_id = ? AND p.shop_id = ?
           AND COALESCE(pv.color, '') = ?
           AND COALESCE(pv.size, '') = ?$exclude_sql
         LIMIT 1",
        $params
    );

    if (!$stmt) {
        return false;
    }

    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

function product_module_sync_product_stock_from_variations($conn, $product_id, $shop_id) {
    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT COALESCE(SUM(pv.stock_qty), 0) AS total_stock
         FROM product_variations pv
         INNER JOIN products p ON p.product_id = pv.product_id
         WHERE pv.product_id = ? AND p.shop_id = ?",
        [(string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        return false;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    $total_stock = (int) ($row['total_stock'] ?? 0);
    $status = $total_stock > 0 ? 'Available' : 'Out of Stock';

    $update_stmt = product_module_prepare_and_execute(
        $conn,
        "UPDATE products
         SET stock_qty = ?, status = ?
         WHERE product_id = ? AND shop_id = ?",
        [(string) $total_stock, $status, (string) $product_id, (string) $shop_id]
    );

    if (!$update_stmt) {
        return false;
    }

    mysqli_stmt_close($update_stmt);
    return true;
}

function product_module_save_product_variation($conn, $variation_id, $product_id, $shop_id, $data, $variation_type) {
    if (!product_module_variation_features_ready($conn)) {
        return [
            'success' => false,
            'message' => 'The product variations schema is not ready yet.'
        ];
    }

    $product = product_module_fetch_product($conn, $product_id, $shop_id);
    if (!$product) {
        return [
            'success' => false,
            'message' => 'The selected product could not be found.'
        ];
    }

    if (product_module_variation_duplicate_exists($conn, $product_id, $shop_id, $data['color'], $data['size'], $variation_id)) {
        return [
            'success' => false,
            'message' => 'This variation already exists for the selected product.'
        ];
    }

    mysqli_begin_transaction($conn);

    if ((int) $variation_id > 0) {
        $existing_variation = product_module_fetch_product_variation($conn, $variation_id, $product_id, $shop_id);
        if (!$existing_variation) {
            mysqli_rollback($conn);
            return [
                'success' => false,
                'message' => 'The selected variation could not be found.'
            ];
        }

        $stmt = product_module_prepare_and_execute(
            $conn,
            "UPDATE product_variations
             SET color = NULLIF(?, ''),
                 size = NULLIF(?, ''),
                 stock_qty = ?
             WHERE variation_id = ? AND product_id = ?",
            [$data['color'], $data['size'], $data['stock_qty'], (string) $variation_id, (string) $product_id]
        );
    } else {
        $stmt = product_module_prepare_and_execute(
            $conn,
            "INSERT INTO product_variations (product_id, color, size, stock_qty)
             VALUES (?, NULLIF(?, ''), NULLIF(?, ''), ?)",
            [(string) $product_id, $data['color'], $data['size'], $data['stock_qty']]
        );
    }

    if (!$stmt) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'Unable to save the variation right now. Please try again.'
        ];
    }
    mysqli_stmt_close($stmt);

    if (!product_module_sync_product_stock_from_variations($conn, $product_id, $shop_id)) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'The variation was not saved because the parent product stock could not be synchronized.'
        ];
    }

    mysqli_commit($conn);
    $labels = product_module_variation_type_labels();

    return [
        'success' => true,
        'message' => ((int) $variation_id > 0 ? 'Updated ' : 'Added ') . strtolower($labels[$variation_type] ?? 'variation') . ' successfully.'
    ];
}

function product_module_delete_product_variation($conn, $variation_id, $product_id, $shop_id, $variation_type = 'variation') {
    if (!product_module_variation_features_ready($conn)) {
        return [
            'success' => false,
            'message' => 'The product variations schema is not ready yet.'
        ];
    }

    $variation = product_module_fetch_product_variation($conn, $variation_id, $product_id, $shop_id);
    if (!$variation) {
        return [
            'success' => false,
            'message' => 'The selected variation could not be found.'
        ];
    }

    mysqli_begin_transaction($conn);

    $stmt = product_module_prepare_and_execute(
        $conn,
        "DELETE FROM product_variations WHERE variation_id = ? AND product_id = ?",
        [(string) $variation_id, (string) $product_id]
    );

    if (!$stmt) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'Unable to delete the selected variation.'
        ];
    }
    mysqli_stmt_close($stmt);

    if (!product_module_sync_product_stock_from_variations($conn, $product_id, $shop_id)) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'The variation could not be deleted because the parent product stock could not be synchronized.'
        ];
    }

    mysqli_commit($conn);

    return [
        'success' => true,
        'message' => 'Deleted ' . strtolower($variation_type) . ' successfully.'
    ];
}

function product_module_save_product($conn, $shop_id, $data, $product_id = 0) {
    mysqli_begin_transaction($conn);

    if ((int) $product_id > 0) {
        $sql = "UPDATE products
                SET product_name = ?,
                    description = NULLIF(?, ''),
                    cost_price = NULLIF(?, ''),
                    selling_price = ?,
                    discount_price = NULLIF(?, ''),
                    stock_qty = ?,
                    reorder_level = ?,
                    weight = NULLIF(?, ''),
                    status = ?,
                    category_id = NULLIF(?, ''),
                    brand_id = NULLIF(?, ''),
                    supplier_id = NULLIF(?, '')
                WHERE product_id = ? AND shop_id = ?";

        $params = [
            $data['product_name'],
            $data['description'],
            $data['cost_price'],
            $data['selling_price'],
            $data['discount_price'],
            $data['stock_qty'],
            $data['reorder_level'],
            $data['weight'],
            $data['status'],
            $data['category_id'],
            $data['brand_id'],
            $data['supplier_id'],
            (string) $product_id,
            (string) $shop_id
        ];
    } else {
        $sql = "INSERT INTO products (
                    shop_id,
                    product_name,
                    description,
                    cost_price,
                    selling_price,
                    discount_price,
                    stock_qty,
                    reorder_level,
                    weight,
                    status,
                    category_id,
                    brand_id,
                    supplier_id,
                    created_at
                ) VALUES (
                    ?,
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    ?,
                    NULLIF(?, ''),
                    ?,
                    ?,
                    NULLIF(?, ''),
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NOW()
                )";

        $params = [
            (string) $shop_id,
            $data['product_name'],
            $data['description'],
            $data['cost_price'],
            $data['selling_price'],
            $data['discount_price'],
            $data['stock_qty'],
            $data['reorder_level'],
            $data['weight'],
            $data['status'],
            $data['category_id'],
            $data['brand_id'],
            $data['supplier_id']
        ];
    }

    $stmt = product_module_prepare_and_execute($conn, $sql, $params);
    if (!$stmt) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'Unable to save the product right now. Please try again.'
        ];
    }

    $saved_product_id = (int) $product_id > 0 ? (int) $product_id : mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);

    return [
        'success' => true,
        'message' => (int) $product_id > 0 ? 'Product updated successfully.' : 'Product added successfully.',
        'product_id' => $saved_product_id
    ];
}

function product_module_delete_product($conn, $product_id, $shop_id) {
    $product = product_module_fetch_product($conn, $product_id, $shop_id);
    if (!$product) {
        return [
            'success' => false,
            'message' => 'The selected product could not be found.'
        ];
    }

    $usage_checks = [
        'order_items' => 'This product cannot be deleted because it is linked to customer orders.',
        'user_feedback' => 'This product cannot be deleted because customers have already left feedback for it.'
    ];

    foreach ($usage_checks as $table => $message) {
        $stmt = product_module_prepare_and_execute(
            $conn,
            "SELECT COUNT(*) AS total_records FROM `$table` WHERE product_id = ?",
            [(string) $product_id]
        );

        if (!$stmt) {
            return [
                'success' => false,
                'message' => 'Unable to validate whether the product can be deleted.'
            ];
        }

        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : ['total_records' => 0];
        mysqli_stmt_close($stmt);

        if ((int) ($row['total_records'] ?? 0) > 0) {
            return [
                'success' => false,
                'message' => $message
            ];
        }
    }

    $product_images = product_module_fetch_product_images($conn, $product_id, $shop_id);

    mysqli_begin_transaction($conn);

    $stmt = product_module_prepare_and_execute(
        $conn,
        "DELETE FROM products WHERE product_id = ? AND shop_id = ?",
        [(string) $product_id, (string) $shop_id]
    );

    if (!$stmt) {
        mysqli_rollback($conn);
        return [
            'success' => false,
            'message' => 'Unable to delete the selected product right now.'
        ];
    }

    mysqli_stmt_close($stmt);
    mysqli_commit($conn);

    foreach ($product_images as $product_image) {
        product_module_remove_image_file($product_image['image_path']);
    }
    product_module_cleanup_empty_image_directories($shop_id, $product_id);

    return [
        'success' => true,
        'message' => 'Product deleted successfully.'
    ];
}

function product_module_order_item_revenue_expression($conn, $alias = 'oi') {
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($alias === '') {
        $alias = 'oi';
    }

    $has_subtotal = product_module_column_exists($conn, 'order_items', 'subtotal');
    $has_unit_price = product_module_column_exists($conn, 'order_items', 'unit_price');

    if ($has_subtotal && $has_unit_price) {
        return "COALESCE($alias.subtotal, ($alias.quantity * COALESCE($alias.unit_price, 0)))";
    }

    if ($has_subtotal) {
        return "COALESCE($alias.subtotal, 0)";
    }

    if ($has_unit_price) {
        return "($alias.quantity * COALESCE($alias.unit_price, 0))";
    }

    return '0';
}

function product_module_fetch_available_order_years($conn, $shop_id) {
    $years = [];
    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT DISTINCT YEAR(order_date) AS report_year
         FROM orders
         WHERE shop_id = ?
         ORDER BY report_year DESC",
        [(string) $shop_id]
    );

    if ($stmt) {
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $report_year = (int) ($row['report_year'] ?? 0);
                if ($report_year > 0) {
                    $years[] = $report_year;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }

    if (empty($years)) {
        $years[] = (int) date('Y');
    }

    return $years;
}

function product_module_fetch_product_performance_rows($conn, $shop_id, $year) {
    $rows = [];
    $revenue_expression = product_module_order_item_revenue_expression($conn, 'oi');
    $sql = "SELECT p.product_id,
                   p.product_name,
                   p.status,
                   p.stock_qty,
                   COALESCE(c.category_name, 'Unassigned') AS category_name,
                   COALESCE(b.brand_name, 'Unassigned') AS brand_name,
                   COALESCE(ps.order_count, 0) AS order_count,
                   COALESCE(ps.units_sold, 0) AS units_sold,
                   COALESCE(ps.revenue, 0) AS revenue
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN brands b ON p.brand_id = b.brand_id
            LEFT JOIN (
                SELECT oi.product_id,
                       COUNT(DISTINCT o.order_id) AS order_count,
                       COALESCE(SUM(oi.quantity), 0) AS units_sold,
                       COALESCE(SUM($revenue_expression), 0) AS revenue
                FROM order_items oi
                INNER JOIN orders o ON o.order_id = oi.order_id
                WHERE o.shop_id = ?
                  AND YEAR(o.order_date) = ?
                  AND LOWER(COALESCE(o.order_status, '')) NOT IN ('cancelled', 'canceled')
                GROUP BY oi.product_id
            ) ps ON ps.product_id = p.product_id
            WHERE p.shop_id = ?
            ORDER BY revenue DESC, units_sold DESC, order_count DESC, p.product_name ASC";

    $stmt = product_module_prepare_and_execute(
        $conn,
        $sql,
        [(string) $shop_id, (string) $year, (string) $shop_id]
    );

    if (!$stmt) {
        return $rows;
    }

    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['product_id'] = (int) ($row['product_id'] ?? 0);
            $row['stock_qty'] = (int) ($row['stock_qty'] ?? 0);
            $row['order_count'] = (int) ($row['order_count'] ?? 0);
            $row['units_sold'] = (int) ($row['units_sold'] ?? 0);
            $row['revenue'] = (float) ($row['revenue'] ?? 0);
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
    return $rows;
}

function product_module_fetch_monthly_product_sales($conn, $shop_id, $year) {
    $revenue_expression = product_module_order_item_revenue_expression($conn, 'oi');
    $report = [
        'months' => [],
        'leaders' => []
    ];

    $month_stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
                MONTH(o.order_date) AS month_number,
                COUNT(DISTINCT o.order_id) AS order_count,
                COUNT(DISTINCT oi.product_id) AS product_count,
                COALESCE(SUM(oi.quantity), 0) AS units_sold,
                COALESCE(SUM($revenue_expression), 0) AS revenue
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.order_id
         INNER JOIN products p ON p.product_id = oi.product_id
         WHERE o.shop_id = ?
           AND p.shop_id = ?
           AND YEAR(o.order_date) = ?
           AND LOWER(COALESCE(o.order_status, '')) NOT IN ('cancelled', 'canceled')
         GROUP BY month_key, month_number
         ORDER BY month_key ASC",
        [(string) $shop_id, (string) $shop_id, (string) $year]
    );

    if ($month_stmt) {
        $result = mysqli_stmt_get_result($month_stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $row['month_number'] = (int) ($row['month_number'] ?? 0);
                $row['order_count'] = (int) ($row['order_count'] ?? 0);
                $row['product_count'] = (int) ($row['product_count'] ?? 0);
                $row['units_sold'] = (int) ($row['units_sold'] ?? 0);
                $row['revenue'] = (float) ($row['revenue'] ?? 0);
                $report['months'][$row['month_key']] = $row;
            }
        }
        mysqli_stmt_close($month_stmt);
    }

    $leader_stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
                p.product_id,
                p.product_name,
                COALESCE(SUM(oi.quantity), 0) AS units_sold,
                COALESCE(SUM($revenue_expression), 0) AS revenue
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.order_id
         INNER JOIN products p ON p.product_id = oi.product_id
         WHERE o.shop_id = ?
           AND p.shop_id = ?
           AND YEAR(o.order_date) = ?
           AND LOWER(COALESCE(o.order_status, '')) NOT IN ('cancelled', 'canceled')
         GROUP BY month_key, p.product_id, p.product_name
         ORDER BY month_key ASC, units_sold DESC, revenue DESC, p.product_name ASC",
        [(string) $shop_id, (string) $shop_id, (string) $year]
    );

    if ($leader_stmt) {
        $result = mysqli_stmt_get_result($leader_stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $month_key = $row['month_key'] ?? '';
                if ($month_key === '' || isset($report['leaders'][$month_key])) {
                    continue;
                }

                $row['product_id'] = (int) ($row['product_id'] ?? 0);
                $row['units_sold'] = (int) ($row['units_sold'] ?? 0);
                $row['revenue'] = (float) ($row['revenue'] ?? 0);
                $report['leaders'][$month_key] = $row;
            }
        }
        mysqli_stmt_close($leader_stmt);
    }

    return $report;
}

function product_module_code_schema_issues($conn) {
    $issues = [];
    $required_columns = ['product_id', 'shop_id', 'product_name', 'selling_price', 'stock_qty', 'status'];

    foreach ($required_columns as $column) {
        if (!product_module_column_exists($conn, 'products', $column)) {
            $issues[] = "Missing required `$column` column on the `products` table.";
        }
    }

    return $issues;
}

function product_module_product_reference_code($shop_id, $product_id) {
    return 'SHOP-' . str_pad((string) ((int) $shop_id), 3, '0', STR_PAD_LEFT) .
        '-PROD-' . str_pad((string) ((int) $product_id), 6, '0', STR_PAD_LEFT);
}

function product_module_reference_code_parts($value) {
    $value = strtoupper(trim((string) $value));
    if ($value === '') {
        return null;
    }

    if (preg_match('/SHOP-(\d+)-PROD-(\d+)/', $value, $matches)) {
        return [
            'shop_id' => (int) $matches[1],
            'product_id' => (int) $matches[2]
        ];
    }

    return null;
}

function product_module_product_code_payload($product, $shop_name = '') {
    $shop_id = (int) ($product['shop_id'] ?? 0);
    $product_id = (int) ($product['product_id'] ?? 0);
    $reference_code = product_module_product_reference_code($shop_id, $product_id);

    return [
        'reference_code' => $reference_code,
        'barcode_value' => $reference_code,
        'qr_value' => $reference_code,
        'display_name' => $product['product_name'] ?? ('Product #' . $product_id),
        'price_label' => product_module_price_label($product['selling_price'] ?? null),
        'stock_label' => number_format((int) ($product['stock_qty'] ?? 0)) . ' units',
        'status_label' => $product['status'] ?? 'Available',
        'shop_label' => $shop_name !== '' ? $shop_name : ('Shop #' . $shop_id)
    ];
}

function product_module_fetch_products_for_codes($conn, $shop_id, $search = '', $limit = 18) {
    $rows = [];
    $limit = max(1, min(50, (int) $limit));
    $search = trim((string) $search);
    $resolved_reference = product_module_reference_code_parts($search);

    $params = [(string) $shop_id];
    $where = ["p.shop_id = ?"];

    if ($resolved_reference && (int) $resolved_reference['shop_id'] === (int) $shop_id) {
        $where[] = "p.product_id = ?";
        $params[] = (string) $resolved_reference['product_id'];
    } elseif ($search !== '') {
        $where[] = "(p.product_name LIKE ? OR p.status LIKE ? OR COALESCE(c.category_name, '') LIKE ? OR COALESCE(b.brand_name, '') LIKE ? OR CAST(p.product_id AS CHAR) = ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $search;
    }

    $stmt = product_module_prepare_and_execute(
        $conn,
        "SELECT p.product_id,
                p.shop_id,
                p.product_name,
                p.selling_price,
                p.stock_qty,
                p.status,
                p.created_at,
                COALESCE(c.category_name, 'Unassigned') AS category_name,
                COALESCE(b.brand_name, 'Unassigned') AS brand_name
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.category_id
         LEFT JOIN brands b ON p.brand_id = b.brand_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY p.created_at DESC, p.product_name ASC
         LIMIT $limit",
        $params
    );

    if (!$stmt) {
        return $rows;
    }

    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['product_id'] = (int) ($row['product_id'] ?? 0);
            $row['shop_id'] = (int) ($row['shop_id'] ?? 0);
            $row['stock_qty'] = (int) ($row['stock_qty'] ?? 0);
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

function product_module_find_product_by_scan_value($conn, $shop_id, $scan_value) {
    $scan_value = trim((string) $scan_value);
    if ($scan_value === '') {
        return null;
    }

    $reference = product_module_reference_code_parts($scan_value);
    if ($reference && (int) $reference['shop_id'] === (int) $shop_id) {
        return product_module_fetch_product($conn, (int) $reference['product_id'], $shop_id);
    }

    if (ctype_digit($scan_value)) {
        return product_module_fetch_product($conn, (int) $scan_value, $shop_id);
    }

    return null;
}

function product_module_safe_file_token($value, $fallback = 'product-label') {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');

    return $value !== '' ? $value : $fallback;
}

function product_module_price_label($amount, $fallback = 'Not set') {
    if ($amount === null || $amount === '') {
        return $fallback;
    }

    return 'LKR ' . number_format((float) $amount, 2);
}

function product_module_excerpt($text, $limit = 80) {
    $text = trim((string) $text);
    if ($text === '') {
        return 'No description available.';
    }

    if (product_module_safe_strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit - 3)) . '...';
}

function product_module_product_badges($product) {
    $badges = [];
    $stock_qty = (int) ($product['stock_qty'] ?? 0);
    $reorder_level = (int) ($product['reorder_level'] ?? 0);
    $status = $product['status'] ?? 'Available';

    if ($status === 'Out of Stock' || $stock_qty <= 0) {
        $badges[] = [
            'class' => 'status-pill status-out',
            'label' => 'Out of Stock'
        ];
    } elseif ($reorder_level > 0 && $stock_qty <= $reorder_level) {
        $badges[] = [
            'class' => 'status-pill status-low',
            'label' => 'Low Stock'
        ];
    } else {
        $badges[] = [
            'class' => 'status-pill status-available',
            'label' => 'Available'
        ];
    }

    if (!empty($product['discount_price']) && (float) $product['discount_price'] > 0) {
        $badges[] = [
            'class' => 'status-pill status-info',
            'label' => 'Discounted'
        ];
    }

    return $badges;
}
?>
