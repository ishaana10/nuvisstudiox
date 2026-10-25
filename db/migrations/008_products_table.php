<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasProducts = false;
        try {
            $hasProducts = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_products'" : "SHOW TABLES LIKE 'nu_products'")->fetch();
        } catch (Exception $e) {}

        if (!$hasProducts) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE `nu_products` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `name` VARCHAR(150) NOT NULL,
                    `type` VARCHAR(50) NOT NULL DEFAULT 'product',
                    `barcode` VARCHAR(50) NOT NULL UNIQUE,
                    `description` TEXT DEFAULT NULL,
                    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TEXT DEFAULT NULL
                )");
            } else {
                $pdo->exec("CREATE TABLE `nu_products` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(150) NOT NULL,
                    `type` VARCHAR(50) NOT NULL DEFAULT 'product',
                    `barcode` VARCHAR(50) NOT NULL UNIQUE,
                    `description` TEXT DEFAULT NULL,
                    `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_product_barcode` (`barcode`),
                    INDEX `idx_product_type` (`type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            // Seed initial products, goods, and services
            $pdo->exec("INSERT INTO `nu_products` (`name`, `type`, `barcode`, `description`, `price`) VALUES
            ('Organic Coffee Beans (Dark Roast)', 'product', '7501031311309', 'Premium single-origin organic arabica coffee beans, slow-roasted to a rich dark finish. 500g bag.', 18.99),
            ('Ergonomic Wireless Mouse', 'product', '074182285197', 'Rechargeable wireless ergonomic mouse with silent clicking, side-scroll wheel, and adjustable DPI settings up to 4000.', 45.50),
            ('Eco-Friendly Bamboo Water Bottle', 'product', '8886367301031', 'Double-walled vacuum insulated water bottle made from stainless steel and covered with natural, sustainable bamboo. 750ml.', 24.99),
            ('Heavy Duty Industrial Pallet', 'good', 'GD-WRH-PAL-02', 'High-density polyethylene structural foam plastic pallet. Ideal for warehouse storage and forklift transport. Rated up to 1500kg.', 79.95),
            ('Ultra-Soft Microfiber Towel Set', 'good', 'GD-TOWEL-S4', 'Pack of 4 quick-drying, highly absorbent premium microfiber towels. Perfect for home, gym, or automotive detailing.', 15.49),
            ('Web Application Development Consultation', 'service', 'SVC-WEB-DEV-01', 'One-hour professional architectural and consulting session with a Senior Software Engineer regarding web app stacks and cloud hosting.', 120.00),
            ('Enterprise IT Security Audit', 'service', 'SVC-IT-SEC-05', 'Comprehensive end-to-end network penetration testing, software vulnerability scan, and infrastructure compliance audit report.', 1500.00)");
        }
    }
};
