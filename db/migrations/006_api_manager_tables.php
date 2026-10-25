<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasEndpoints = false;
        try {
            $hasEndpoints = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_api_endpoints'" : "SHOW TABLES LIKE 'nu_api_endpoints'")->fetch();
        } catch (Exception $e) {}

        if (!$hasEndpoints) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE `nu_api_endpoints` (
                    `endpoint_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `endpoint_name` VARCHAR(150) NOT NULL,
                    `endpoint_route` VARCHAR(250) NOT NULL UNIQUE,
                    `endpoint_method` VARCHAR(10) NOT NULL DEFAULT 'GET',
                    `endpoint_type` VARCHAR(20) NOT NULL DEFAULT 'form',
                    `endpoint_target` VARCHAR(200) NOT NULL,
                    `endpoint_active` INTEGER NOT NULL DEFAULT 1,
                    `endpoint_config` TEXT DEFAULT NULL,
                    `endpoint_created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $pdo->exec("CREATE TABLE `nu_api_endpoints` (
                    `endpoint_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `endpoint_name` VARCHAR(150) NOT NULL,
                    `endpoint_route` VARCHAR(250) NOT NULL UNIQUE,
                    `endpoint_method` VARCHAR(10) NOT NULL DEFAULT 'GET',
                    `endpoint_type` VARCHAR(20) NOT NULL DEFAULT 'form',
                    `endpoint_target` VARCHAR(200) NOT NULL,
                    `endpoint_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `endpoint_config` TEXT DEFAULT NULL,
                    `endpoint_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_api_route` (`endpoint_route`),
                    INDEX `idx_api_active` (`endpoint_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        }

        $hasLogs = false;
        try {
            $hasLogs = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_api_logs'" : "SHOW TABLES LIKE 'nu_api_logs'")->fetch();
        } catch (Exception $e) {}

        if (!$hasLogs) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE `nu_api_logs` (
                    `log_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `log_route` VARCHAR(250) NOT NULL,
                    `log_method` VARCHAR(10) NOT NULL,
                    `log_token_name` VARCHAR(100) DEFAULT NULL,
                    `log_user_id` VARCHAR(64) DEFAULT NULL,
                    `log_request_payload` TEXT DEFAULT NULL,
                    `log_response_code` INTEGER NOT NULL,
                    `log_response_payload` TEXT DEFAULT NULL,
                    `log_duration` DOUBLE DEFAULT NULL,
                    `log_created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $pdo->exec("CREATE TABLE `nu_api_logs` (
                    `log_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `log_route` VARCHAR(250) NOT NULL,
                    `log_method` VARCHAR(10) NOT NULL,
                    `log_token_name` VARCHAR(100) DEFAULT NULL,
                    `log_user_id` VARCHAR(64) DEFAULT NULL,
                    `log_request_payload` TEXT DEFAULT NULL,
                    `log_response_code` INT NOT NULL,
                    `log_response_payload` TEXT DEFAULT NULL,
                    `log_duration` DOUBLE DEFAULT NULL,
                    `log_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_log_route` (`log_route`),
                    INDEX `idx_log_created` (`log_created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        }
    }
};
