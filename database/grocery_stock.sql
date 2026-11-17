-- =====================================================================
--  Grocery Stock Management System - Database Schema & Seed Data
--  Target : MySQL 5.7+ / MariaDB 10.3+ (XAMPP & InfinityFree)
--  Note   : No CREATE DATABASE / USE statements so this file imports
--           directly into an existing database (e.g. InfinityFree).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- users : system accounts that can log in and record stock movements
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(100) NOT NULL,
  `username`      VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- categories : product groupings (Beverages, Snacks, ...)
-- ---------------------------------------------------------------------
CREATE TABLE `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(80)  NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- suppliers : companies / distributors that deliver stock
-- ---------------------------------------------------------------------
CREATE TABLE `suppliers` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(120) NOT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `phone`          VARCHAR(30)  DEFAULT NULL,
  `email`          VARCHAR(120) DEFAULT NULL,
  `address`        VARCHAR(255) DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- products : items sold in the store (main CRUD table)
-- ---------------------------------------------------------------------
CREATE TABLE `products` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `sku`           VARCHAR(40)   NOT NULL,
  `name`          VARCHAR(120)  NOT NULL,
  `category_id`   INT UNSIGNED  NOT NULL,
  `supplier_id`   INT UNSIGNED  DEFAULT NULL,
  `unit`          VARCHAR(20)   NOT NULL DEFAULT 'pc',
  `cost_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity`      INT           NOT NULL DEFAULT 0,
  `reorder_level` INT           NOT NULL DEFAULT 10,
  `expiry_date`   DATE          DEFAULT NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_supplier` (`supplier_id`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_products_supplier` FOREIGN KEY (`supplier_id`)
    REFERENCES `suppliers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- stock_movements : audit log of every stock in / out / adjustment
-- ---------------------------------------------------------------------
CREATE TABLE `stock_movements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `type`       ENUM('in','out','adjust') NOT NULL,
  `quantity`   INT          NOT NULL,
  `remarks`    VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movements_product` (`product_id`),
  KEY `idx_movements_user` (`user_id`),
  KEY `idx_movements_created` (`created_at`),
  CONSTRAINT `fk_movements_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_movements_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SEED DATA
-- =====================================================================

-- Default admin account  ->  username: admin   password: admin123
INSERT INTO `users` (`full_name`, `username`, `password_hash`) VALUES
('Store Administrator', 'admin', '$2y$10$900aK/EtC5W58ALOZBR33.tnNJn5Fh.cT1B/a7F6q8dF2VjO8.Vr.');

INSERT INTO `categories` (`name`, `description`) VALUES
('Beverages',      'Soft drinks, juices, water, coffee and tea'),
('Snacks',         'Chips, biscuits, crackers and candies'),
('Canned Goods',   'Canned meat, fish, fruits and vegetables'),
('Dairy & Eggs',   'Milk, cheese, butter and eggs'),
('Instant Food',   'Noodles, cup meals and ready-to-eat items'),
('Bread & Bakery', 'Loaves, buns and pastries'),
('Personal Care',  'Soap, shampoo, toothpaste and hygiene items'),
('Household',      'Detergents, cleaners and tissue');

INSERT INTO `suppliers` (`name`, `contact_person`, `phone`, `email`, `address`) VALUES
('Metro Beverage Distributors', 'Carlos Reyes',   '0917-555-0101', 'orders@metrobev.example',   'Quezon City, Metro Manila'),
('SnackWorld Trading',          'Liza Santos',    '0918-555-0202', 'sales@snackworld.example',  'Pasig City, Metro Manila'),
('Golden Harvest Foods',        'Ramon Dela Cruz','0919-555-0303', 'supply@goldenharvest.example','Iloilo City, Iloilo'),
('FreshFarm Dairy Co.',         'Ana Villanueva', '0920-555-0404', 'hello@freshfarm.example',   'Batangas City, Batangas'),
('CleanHome Essentials',        'Mark Tan',       '0921-555-0505', 'info@cleanhome.example',    'Cebu City, Cebu');

-- Expiry dates are relative to import date so alerts always have data.
INSERT INTO `products`
(`sku`, `name`, `category_id`, `supplier_id`, `unit`, `cost_price`, `selling_price`, `quantity`, `reorder_level`, `expiry_date`) VALUES
('BEV-001', 'Cola 1.5L',                   1, 1, 'bottle', 52.00,  68.00,  48, 12, DATE_ADD(CURDATE(), INTERVAL 180 DAY)),
('BEV-002', 'Bottled Water 500ml',          1, 1, 'bottle', 9.50,   15.00, 120, 24, DATE_ADD(CURDATE(), INTERVAL 365 DAY)),
('BEV-003', 'Orange Juice 1L',              1, 1, 'box',    58.00,  75.00,   6, 10, DATE_ADD(CURDATE(), INTERVAL 20 DAY)),
('BEV-004', '3-in-1 Coffee Mix (10s)',      1, 1, 'pack',   62.00,  80.00,  35, 10, DATE_ADD(CURDATE(), INTERVAL 270 DAY)),
('BEV-005', 'Energy Drink 250ml',           1, 1, 'can',    28.00,  38.00,   0, 12, DATE_ADD(CURDATE(), INTERVAL 200 DAY)),
('SNK-001', 'Potato Chips Original 60g',    2, 2, 'pack',   24.00,  35.00,  60, 15, DATE_ADD(CURDATE(), INTERVAL 120 DAY)),
('SNK-002', 'Chocolate Sandwich Cookies',   2, 2, 'pack',   18.00,  27.00,  42, 15, DATE_ADD(CURDATE(), INTERVAL 150 DAY)),
('SNK-003', 'Cheese Crackers 10s',          2, 2, 'pack',   45.00,  60.00,   8, 10, DATE_ADD(CURDATE(), INTERVAL 90 DAY)),
('SNK-004', 'Peanuts Garlic 100g',          2, 2, 'pack',   20.00,  30.00,  25, 10, DATE_ADD(CURDATE(), INTERVAL 15 DAY)),
('CAN-001', 'Corned Beef 150g',             3, 3, 'can',    38.00,  52.00,  54, 12, DATE_ADD(CURDATE(), INTERVAL 540 DAY)),
('CAN-002', 'Sardines in Tomato Sauce 155g',3, 3, 'can',    19.00,  26.00,  80, 20, DATE_ADD(CURDATE(), INTERVAL 600 DAY)),
('CAN-003', 'Tuna Flakes in Oil 180g',      3, 3, 'can',    35.00,  46.00,   9, 12, DATE_ADD(CURDATE(), INTERVAL 480 DAY)),
('CAN-004', 'Fruit Cocktail 432g',          3, 3, 'can',    68.00,  89.00,  18,  6, DATE_ADD(CURDATE(), INTERVAL 400 DAY)),
('DRY-001', 'Fresh Milk 1L',                4, 4, 'box',    85.00, 105.00,  14,  8, DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
('DRY-002', 'Cheddar Cheese 165g',          4, 4, 'pack',   55.00,  72.00,  20,  6, DATE_ADD(CURDATE(), INTERVAL 60 DAY)),
('DRY-003', 'Eggs Large (dozen)',           4, 4, 'tray',   95.00, 120.00,   4,  5, DATE_ADD(CURDATE(), INTERVAL 10 DAY)),
('DRY-004', 'Yogurt Strawberry 100g',       4, 4, 'cup',    22.00,  32.00,  12,  6, DATE_SUB(CURDATE(), INTERVAL 2 DAY)),
('INS-001', 'Instant Noodles Chicken',      5, 3, 'pack',   10.00,  15.00, 150, 30, DATE_ADD(CURDATE(), INTERVAL 240 DAY)),
('INS-002', 'Cup Noodles Beef',             5, 3, 'cup',    24.00,  34.00,  36, 12, DATE_ADD(CURDATE(), INTERVAL 210 DAY)),
('BRD-001', 'White Sandwich Bread',         6, 3, 'loaf',   55.00,  70.00,  10,  5, DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
('BRD-002', 'Pandesal (10s)',               6, 3, 'pack',   30.00,  40.00,   3,  6, DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
('PCR-001', 'Bath Soap 135g',               7, 5, 'bar',    32.00,  45.00,  40, 10, NULL),
('PCR-002', 'Shampoo Sachet (12s)',         7, 5, 'strip',  60.00,  78.00,  22,  8, NULL),
('PCR-003', 'Toothpaste 150g',              7, 5, 'tube',   70.00,  92.00,   7, 8, NULL),
('HSH-001', 'Laundry Powder 1kg',           8, 5, 'pack',   85.00, 110.00,  16,  6, NULL),
('HSH-002', 'Dishwashing Liquid 250ml',     8, 5, 'bottle', 38.00,  52.00,  28,  8, NULL),
('HSH-003', 'Bathroom Tissue (4 rolls)',    8, 5, 'pack',   65.00,  85.00,   0,  6, NULL);

-- Sample movement history spread across the past week (for the dashboard chart)
INSERT INTO `stock_movements` (`product_id`, `user_id`, `type`, `quantity`, `remarks`, `created_at`) VALUES
(1,  1, 'in',  24, 'Weekly delivery',          DATE_SUB(NOW(), INTERVAL 6 DAY)),
(2,  1, 'in',  48, 'Weekly delivery',          DATE_SUB(NOW(), INTERVAL 6 DAY)),
(18, 1, 'in',  60, 'Restock noodles',          DATE_SUB(NOW(), INTERVAL 5 DAY)),
(6,  1, 'out', 12, 'Sold',                     DATE_SUB(NOW(), INTERVAL 5 DAY)),
(11, 1, 'in',  40, 'Supplier delivery',        DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1,  1, 'out', 10, 'Sold',                     DATE_SUB(NOW(), INTERVAL 4 DAY)),
(14, 1, 'in',  12, 'Dairy delivery',           DATE_SUB(NOW(), INTERVAL 3 DAY)),
(18, 1, 'out', 25, 'Sold',                     DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5,  1, 'out', 12, 'Sold out',                 DATE_SUB(NOW(), INTERVAL 2 DAY)),
(22, 1, 'in',  20, 'Personal care delivery',   DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2,  1, 'out', 30, 'Sold',                     DATE_SUB(NOW(), INTERVAL 1 DAY)),
(17, 1, 'adjust', 12, 'Physical count correction', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(10, 1, 'in',  24, 'Supplier delivery',        NOW()),
(7,  1, 'out',  8, 'Sold',                     NOW());
