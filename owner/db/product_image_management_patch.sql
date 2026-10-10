-- ============================================================
-- Product Image Management Module Patch
-- Run this after importing ecommerce_db.sql
-- and after applying owner_module_patch.sql / product_management_patch.sql
-- ============================================================

USE `ecommerce_db`;

SET @db_name = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'product_images'
          AND COLUMN_NAME = 'is_main'
    ),
    'SELECT ''product_images.is_main already exists'' AS message',
    'ALTER TABLE `product_images` ADD COLUMN `is_main` TINYINT(1) NOT NULL DEFAULT 0 AFTER `image_path`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'product_images'
          AND COLUMN_NAME = 'created_at'
    ),
    'SELECT ''product_images.created_at already exists'' AS message',
    'ALTER TABLE `product_images` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_main`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'product_images'
          AND COLUMN_NAME = 'updated_at'
    ),
    'SELECT ''product_images.updated_at already exists'' AS message',
    'ALTER TABLE `product_images` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'product_images'
          AND INDEX_NAME = 'idx_product_images_main'
    ),
    'SELECT ''idx_product_images_main already exists'' AS message',
    'ALTER TABLE `product_images` ADD INDEX `idx_product_images_main` (`product_id`, `is_main`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `product_images`
SET `is_main` = 0;

UPDATE `product_images` pi
INNER JOIN (
    SELECT MIN(`image_id`) AS first_image_id
    FROM `product_images`
    GROUP BY `product_id`
) first_images ON first_images.first_image_id = pi.image_id
SET pi.`is_main` = 1;
