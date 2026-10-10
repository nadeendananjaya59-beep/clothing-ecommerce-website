-- ============================================================
-- Product Management Module - Shop Scope Patch
-- Run this after importing ecommerce_db.sql and owner_module_patch.sql
-- ============================================================

USE `ecommerce_db`;

SET @db_name = DATABASE();

-- ------------------------------------------------------------
-- Add shop_id to product-related tables when missing
-- ------------------------------------------------------------

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'products'
          AND COLUMN_NAME = 'shop_id'
    ),
    'SELECT ''products.shop_id already exists'' AS message',
    'ALTER TABLE `products` ADD COLUMN `shop_id` INT(11) NULL DEFAULT NULL AFTER `product_id`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'categories'
          AND COLUMN_NAME = 'shop_id'
    ),
    'SELECT ''categories.shop_id already exists'' AS message',
    'ALTER TABLE `categories` ADD COLUMN `shop_id` INT(11) NULL DEFAULT NULL AFTER `category_id`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'brands'
          AND COLUMN_NAME = 'shop_id'
    ),
    'SELECT ''brands.shop_id already exists'' AS message',
    'ALTER TABLE `brands` ADD COLUMN `shop_id` INT(11) NULL DEFAULT NULL AFTER `brand_id`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'suppliers'
          AND COLUMN_NAME = 'shop_id'
    ),
    'SELECT ''suppliers.shop_id already exists'' AS message',
    'ALTER TABLE `suppliers` ADD COLUMN `shop_id` INT(11) NULL DEFAULT NULL AFTER `supplier_id`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Add helpful indexes for the owner product module
-- ------------------------------------------------------------

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'products'
          AND INDEX_NAME = 'idx_products_shop_status'
    ),
    'SELECT ''idx_products_shop_status already exists'' AS message',
    'ALTER TABLE `products` ADD INDEX `idx_products_shop_status` (`shop_id`, `status`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'products'
          AND INDEX_NAME = 'idx_products_shop_category_brand'
    ),
    'SELECT ''idx_products_shop_category_brand already exists'' AS message',
    'ALTER TABLE `products` ADD INDEX `idx_products_shop_category_brand` (`shop_id`, `category_id`, `brand_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'categories'
          AND INDEX_NAME = 'idx_categories_shop_id'
    ),
    'SELECT ''idx_categories_shop_id already exists'' AS message',
    'ALTER TABLE `categories` ADD INDEX `idx_categories_shop_id` (`shop_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'brands'
          AND INDEX_NAME = 'idx_brands_shop_id'
    ),
    'SELECT ''idx_brands_shop_id already exists'' AS message',
    'ALTER TABLE `brands` ADD INDEX `idx_brands_shop_id` (`shop_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'suppliers'
          AND INDEX_NAME = 'idx_suppliers_shop_id'
    ),
    'SELECT ''idx_suppliers_shop_id already exists'' AS message',
    'ALTER TABLE `suppliers` ADD INDEX `idx_suppliers_shop_id` (`shop_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Add foreign keys to shops when possible
-- ------------------------------------------------------------

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'shops'
    )
    AND EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'products'
          AND COLUMN_NAME = 'shop_id'
    )
    AND NOT EXISTS(
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = @db_name
          AND TABLE_NAME = 'products'
          AND CONSTRAINT_NAME = 'fk_products_shop_owner'
    ),
    'ALTER TABLE `products` ADD CONSTRAINT `fk_products_shop_owner` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE CASCADE ON UPDATE CASCADE',
    'SELECT ''Skipping products shop foreign key'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'shops'
    )
    AND EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'categories'
          AND COLUMN_NAME = 'shop_id'
    )
    AND NOT EXISTS(
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = @db_name
          AND TABLE_NAME = 'categories'
          AND CONSTRAINT_NAME = 'fk_categories_shop_owner'
    ),
    'ALTER TABLE `categories` ADD CONSTRAINT `fk_categories_shop_owner` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE CASCADE ON UPDATE CASCADE',
    'SELECT ''Skipping categories shop foreign key'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'shops'
    )
    AND EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'brands'
          AND COLUMN_NAME = 'shop_id'
    )
    AND NOT EXISTS(
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = @db_name
          AND TABLE_NAME = 'brands'
          AND CONSTRAINT_NAME = 'fk_brands_shop_owner'
    ),
    'ALTER TABLE `brands` ADD CONSTRAINT `fk_brands_shop_owner` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE CASCADE ON UPDATE CASCADE',
    'SELECT ''Skipping brands shop foreign key'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'shops'
    )
    AND EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'suppliers'
          AND COLUMN_NAME = 'shop_id'
    )
    AND NOT EXISTS(
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = @db_name
          AND TABLE_NAME = 'suppliers'
          AND CONSTRAINT_NAME = 'fk_suppliers_shop_owner'
    ),
    'ALTER TABLE `suppliers` ADD CONSTRAINT `fk_suppliers_shop_owner` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE CASCADE ON UPDATE CASCADE',
    'SELECT ''Skipping suppliers shop foreign key'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Backfill existing products to the first available shop so
-- legacy catalog records appear in the owner module.
-- ------------------------------------------------------------

UPDATE `products`
SET `shop_id` = (SELECT MIN(`shop_id`) FROM `shops`)
WHERE `shop_id` IS NULL
  AND (SELECT COUNT(*) FROM `shops`) > 0;
