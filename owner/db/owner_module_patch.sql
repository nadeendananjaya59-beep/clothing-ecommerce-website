-- ============================================================
-- Shop Owner Authentication Module - Additional SQL
-- Run this in phpMyAdmin AFTER importing ecommerce_db.sql
-- ============================================================

USE `ecommerce_db`;

-- --------------------------------------------------------
-- Add 'Shop Owner' role to user_roles
-- --------------------------------------------------------
INSERT IGNORE INTO `user_roles` (`role_id`, `role_name`) VALUES (5, 'Shop Owner');

-- --------------------------------------------------------
-- Table structure for table `shops`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shops` (
  `shop_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `shop_name` varchar(150) NOT NULL,
  `shop_description` text DEFAULT NULL,
  `shop_address` text DEFAULT NULL,
  `shop_phone` varchar(20) DEFAULT NULL,
  `shop_email` varchar(100) DEFAULT NULL,
  `shop_logo` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive','Suspended') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`shop_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `shops_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Table structure for table `password_resets`
-- Stores tokens for forgot password feature
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `reset_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `pr_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Add status column to users table if not exists
-- --------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `status` enum('Active','Inactive','Suspended') DEFAULT 'Active' AFTER `role_id`,
  ADD COLUMN IF NOT EXISTS `profile_image` varchar(255) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `last_login` datetime DEFAULT NULL AFTER `profile_image`;
