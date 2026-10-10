<?php
/**
 * Unified schema updater for the owner dashboard modules.
 * Adds shop isolation columns and product image management fields.
 */
require_once __DIR__ . '/config.php';

function schema_column_exists($conn, $table, $column) {
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && mysqli_num_rows($result) > 0;
}

function schema_index_exists($conn, $table, $index_name) {
    $sql = "SHOW INDEX FROM `$table` WHERE Key_name = '$index_name'";
    $result = mysqli_query($conn, $sql);
    return $result && mysqli_num_rows($result) > 0;
}

function schema_constraint_exists($conn, $table, $constraint_name) {
    $db_name = mysqli_real_escape_string($conn, DB_NAME);
    $table = mysqli_real_escape_string($conn, $table);
    $constraint_name = mysqli_real_escape_string($conn, $constraint_name);

    $sql = "SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = '$db_name'
              AND TABLE_NAME = '$table'
              AND CONSTRAINT_NAME = '$constraint_name'
            LIMIT 1";

    $result = mysqli_query($conn, $sql);
    return $result && mysqli_num_rows($result) > 0;
}

function schema_echo($message) {
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "\n";
}

$shop_tables = [
    'products'   => 'product_id',
    'orders'     => 'order_id',
    'categories' => 'category_id',
    'brands'     => 'brand_id',
    'suppliers'  => 'supplier_id'
];

echo "<h2 style='font-family:sans-serif;'>Owner Dashboard Schema Updater</h2>";
echo "<pre style='background:#f4f4f4;padding:15px;border-radius:5px;font-family:Consolas,monospace;white-space:pre-wrap;'>";

schema_echo("Step 1: Ensuring shop_id exists on owner-scoped tables...");

foreach ($shop_tables as $table => $primary_key) {
    if (!schema_column_exists($conn, $table, 'shop_id')) {
        $alter = "ALTER TABLE `$table` ADD COLUMN `shop_id` INT(11) NULL DEFAULT NULL AFTER `$primary_key`";
        if (mysqli_query($conn, $alter)) {
            schema_echo("[OK] Added shop_id to `$table`.");
        } else {
            schema_echo("[ERROR] Failed to add shop_id to `$table`: " . mysqli_error($conn));
            continue;
        }
    } else {
        schema_echo("[SKIP] `$table`.shop_id already exists.");
    }

    if (schema_column_exists($conn, 'shops', 'shop_id')) {
        $constraint_name = "fk_{$table}_shops";
        if (!schema_constraint_exists($conn, $table, $constraint_name)) {
            $fk_query = "ALTER TABLE `$table`
                         ADD CONSTRAINT `$constraint_name`
                         FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`)
                         ON DELETE CASCADE ON UPDATE CASCADE";
            if (mysqli_query($conn, $fk_query)) {
                schema_echo("   [OK] Added foreign key `$constraint_name` on `$table`.");
            } else {
                schema_echo("   [WARN] Could not add foreign key `$constraint_name`: " . mysqli_error($conn));
            }
        } else {
            schema_echo("   [SKIP] Foreign key `$constraint_name` already exists.");
        }
    }
}

$shop_res = mysqli_query($conn, "SELECT shop_id FROM shops ORDER BY shop_id ASC LIMIT 1");
if ($shop_res && mysqli_num_rows($shop_res) > 0) {
    $shop = mysqli_fetch_assoc($shop_res);
    $default_shop_id = (int) $shop['shop_id'];

    schema_echo("");
    schema_echo("Step 2: Backfilling existing records with Shop ID #$default_shop_id...");

    foreach (array_keys($shop_tables) as $table) {
        $update = "UPDATE `$table` SET `shop_id` = $default_shop_id WHERE `shop_id` IS NULL";
        if (mysqli_query($conn, $update)) {
            schema_echo("   [OK] Updated " . mysqli_affected_rows($conn) . " record(s) in `$table`.");
        } else {
            schema_echo("   [WARN] Failed to backfill `$table`: " . mysqli_error($conn));
        }
    }
} else {
    schema_echo("");
    schema_echo("[WARN] No shop record found. Existing records were not backfilled.");
}

schema_echo("");
schema_echo("Step 3: Ensuring product_images supports the image gallery module...");

$image_columns = [
    'is_main' => "ALTER TABLE `product_images` ADD COLUMN `is_main` TINYINT(1) NOT NULL DEFAULT 0 AFTER `image_path`",
    'created_at' => "ALTER TABLE `product_images` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_main`",
    'updated_at' => "ALTER TABLE `product_images` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
];

foreach ($image_columns as $column => $sql) {
    if (!schema_column_exists($conn, 'product_images', $column)) {
        if (mysqli_query($conn, $sql)) {
            schema_echo("[OK] Added `$column` to `product_images`.");
        } else {
            schema_echo("[ERROR] Failed to add `$column` to `product_images`: " . mysqli_error($conn));
        }
    } else {
        schema_echo("[SKIP] `product_images`.$column already exists.");
    }
}

if (!schema_index_exists($conn, 'product_images', 'idx_product_images_main')) {
    $index_sql = "ALTER TABLE `product_images` ADD INDEX `idx_product_images_main` (`product_id`, `is_main`)";
    if (mysqli_query($conn, $index_sql)) {
        schema_echo("[OK] Added `idx_product_images_main` index.");
    } else {
        schema_echo("[WARN] Could not add `idx_product_images_main`: " . mysqli_error($conn));
    }
} else {
    schema_echo("[SKIP] `idx_product_images_main` already exists.");
}

if (schema_column_exists($conn, 'product_images', 'is_main')) {
    if (mysqli_query($conn, "UPDATE `product_images` SET `is_main` = 0")) {
        schema_echo("[OK] Reset all main-image flags before rebuilding defaults.");
    } else {
        schema_echo("[WARN] Could not reset main-image flags: " . mysqli_error($conn));
    }

    $main_reset_sql = "UPDATE `product_images` pi
                       INNER JOIN (
                           SELECT MIN(`image_id`) AS first_image_id
                           FROM `product_images`
                           GROUP BY `product_id`
                       ) first_images ON first_images.first_image_id = pi.image_id
                       SET pi.`is_main` = 1";

    if (mysqli_query($conn, $main_reset_sql)) {
        schema_echo("[OK] Assigned a default main image for each product gallery.");
    } else {
        schema_echo("[WARN] Could not assign default main images: " . mysqli_error($conn));
    }
}

schema_echo("");
schema_echo("Schema update process complete.");

echo "</pre>";
echo "<a href='../dashboard.php' style='font-family:sans-serif;'>Back to Dashboard</a>";
?>
