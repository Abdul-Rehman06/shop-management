SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `shop` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `shop`;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_remember_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `selector` CHAR(24) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `user_agent_hash` CHAR(64) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_remember_tokens_selector` (`selector`),
  KEY `idx_admin_remember_tokens_admin_id` (`admin_id`),
  KEY `idx_admin_remember_tokens_expires_at` (`expires_at`),
  CONSTRAINT `fk_admin_remember_tokens_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `load_networks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `network_name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_load_networks_network_name` (`network_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `load_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `network` VARCHAR(50) NOT NULL,
  `type` ENUM('opening','purchase','sale') NOT NULL,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `purchased` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sold` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `customer_number` VARCHAR(30) NULL,
  `profit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `closing_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `supplier` VARCHAR(100) NULL,
  `remarks` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_load_transactions_date` (`date`),
  KEY `idx_load_transactions_network` (`network`),
  KEY `idx_load_transactions_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `easypaisa_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `customer_name` VARCHAR(120) NULL,
  `number` VARCHAR(30) NOT NULL,
  `transaction_id` VARCHAR(100) NULL,
  `type` ENUM('receiving','sending') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_easypaisa_date` (`date`),
  KEY `idx_easypaisa_type` (`type`),
  KEY `idx_easypaisa_number` (`number`),
  KEY `idx_easypaisa_transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jazzcash_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `customer_name` VARCHAR(120) NULL,
  `number` VARCHAR(30) NOT NULL,
  `transaction_id` VARCHAR(100) NULL,
  `type` ENUM('receiving','sending') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jazzcash_date` (`date`),
  KEY `idx_jazzcash_type` (`type`),
  KEY `idx_jazzcash_number` (`number`),
  KEY `idx_jazzcash_transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `bank_name` VARCHAR(120) NOT NULL,
  `account_number` VARCHAR(50) NOT NULL,
  `transaction_id` VARCHAR(100) NULL,
  `type` ENUM('receiving','sending') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `charges` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_date` (`date`),
  KEY `idx_bank_type` (`type`),
  KEY `idx_bank_bank_name` (`bank_name`),
  KEY `idx_bank_transaction_id` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `category` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `description` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_date` (`date`),
  KEY `idx_expenses_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_name` VARCHAR(150) NOT NULL,
  `purchase_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sale_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `stock` INT NOT NULL DEFAULT 0,
  `low_stock_limit` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_product_name` (`product_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `sale_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `profit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sales_product_id` (`product_id`),
  KEY `idx_sales_created_at` (`created_at`),
  CONSTRAINT `fk_sales_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
