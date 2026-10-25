<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasPolicy = false;
        try {
            $hasPolicy = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_password_policy'" : "SHOW TABLES LIKE 'nu_password_policy'")->fetch();
        } catch (Exception $e) {}

        if (!$hasPolicy) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_policy` (
                    `policy_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `policy_min_length` INTEGER NOT NULL DEFAULT 8,
                    `policy_require_uppercase` INTEGER NOT NULL DEFAULT 1,
                    `policy_require_lowercase` INTEGER NOT NULL DEFAULT 1,
                    `policy_require_number` INTEGER NOT NULL DEFAULT 1,
                    `policy_require_special` INTEGER NOT NULL DEFAULT 0,
                    `policy_disallow_username` INTEGER NOT NULL DEFAULT 1,
                    `policy_history_count` INTEGER NOT NULL DEFAULT 5,
                    `policy_expiry_days` INTEGER NOT NULL DEFAULT 0,
                    `policy_expiry_warning_days` INTEGER NOT NULL DEFAULT 7,
                    `policy_force_change_on_first_login` INTEGER NOT NULL DEFAULT 1,
                    `policy_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
                $pdo->exec("INSERT OR IGNORE INTO `nu_password_policy` (`policy_id`) VALUES (1)");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_policy` (
                    `policy_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `policy_min_length` INT NOT NULL DEFAULT 8,
                    `policy_require_uppercase` TINYINT(1) NOT NULL DEFAULT 1,
                    `policy_require_lowercase` TINYINT(1) NOT NULL DEFAULT 1,
                    `policy_require_number` TINYINT(1) NOT NULL DEFAULT 1,
                    `policy_require_special` TINYINT(1) NOT NULL DEFAULT 0,
                    `policy_disallow_username` TINYINT(1) NOT NULL DEFAULT 1,
                    `policy_history_count` INT NOT NULL DEFAULT 5,
                    `policy_expiry_days` INT NOT NULL DEFAULT 0,
                    `policy_expiry_warning_days` INT NOT NULL DEFAULT 7,
                    `policy_force_change_on_first_login` TINYINT(1) NOT NULL DEFAULT 1,
                    `policy_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->exec("INSERT IGNORE INTO `nu_password_policy` (`policy_id`) VALUES (1)");
            }
        }

        $hasHistory = false;
        try {
            $hasHistory = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_password_history'" : "SHOW TABLES LIKE 'nu_password_history'")->fetch();
        } catch (Exception $e) {}

        if (!$hasHistory) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_history` (
                    `ph_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `ph_user_id` TEXT NOT NULL,
                    `ph_hash` TEXT NOT NULL,
                    `ph_created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_history` (
                    `ph_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `ph_user_id` VARCHAR(64) NOT NULL,
                    `ph_hash` VARCHAR(255) NOT NULL,
                    `ph_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_ph_user` (`ph_user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        }
    }
};
