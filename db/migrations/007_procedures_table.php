<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasProcedures = false;
        try {
            $hasProcedures = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_procedures'" : "SHOW TABLES LIKE 'nu_procedures'")->fetch();
        } catch (Exception $e) {}

        if (!$hasProcedures) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE `nu_procedures` (
                    `procedure_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `procedure_name` VARCHAR(150) NOT NULL,
                    `procedure_code` VARCHAR(100) NOT NULL UNIQUE,
                    `procedure_description` VARCHAR(255) DEFAULT NULL,
                    `procedure_php` TEXT DEFAULT NULL,
                    `procedure_active` INTEGER NOT NULL DEFAULT 1,
                    `procedure_created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                    `procedure_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $pdo->exec("CREATE TABLE `nu_procedures` (
                    `procedure_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `procedure_name` VARCHAR(150) NOT NULL,
                    `procedure_code` VARCHAR(100) NOT NULL UNIQUE,
                    `procedure_description` VARCHAR(255) DEFAULT NULL,
                    `procedure_php` MEDIUMTEXT DEFAULT NULL,
                    `procedure_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `procedure_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `procedure_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_proc_code` (`procedure_code`),
                    INDEX `idx_proc_active` (`procedure_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        }
    }
};
