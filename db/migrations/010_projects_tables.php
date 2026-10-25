<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasProjects = false;
        try {
            $hasProjects = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_projects'" : "SHOW TABLES LIKE 'nu_projects'")->fetch();
        } catch (Exception $e) {}

        if (!$hasProjects) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_projects` (
                    `project_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `project_code` TEXT NOT NULL UNIQUE,
                    `project_name` TEXT NOT NULL,
                    `project_description` TEXT NULL,
                    `project_settings` TEXT NULL,
                    `project_active` INTEGER NOT NULL DEFAULT 1,
                    `project_is_default` INTEGER NOT NULL DEFAULT 0,
                    `project_owner_id` INTEGER NULL,
                    `project_is_public_test` INTEGER NOT NULL DEFAULT 0,
                    `project_share_token` TEXT NULL,
                    `project_share_mode` TEXT DEFAULT 'guest',
                    `project_created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                    `project_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_projects` (
                    `project_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `project_code` VARCHAR(50) NOT NULL,
                    `project_name` VARCHAR(150) NOT NULL,
                    `project_description` TEXT NULL,
                    `project_settings` LONGTEXT NULL,
                    `project_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `project_is_default` TINYINT(1) NOT NULL DEFAULT 0,
                    `project_owner_id` INT NULL,
                    `project_is_public_test` TINYINT(1) NOT NULL DEFAULT 0,
                    `project_share_token` VARCHAR(64) NULL,
                    `project_share_mode` VARCHAR(20) NOT NULL DEFAULT 'guest',
                    `project_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `project_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`project_id`),
                    UNIQUE KEY `uq_project_code` (`project_code`),
                    KEY `idx_project_active` (`project_active`),
                    KEY `idx_project_share_token` (`project_share_token`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        }

        // Ensure project columns exist
        try {
            $hasPublicCol = false;
            if ($driver === 'sqlite') {
                $pCols = $pdo->query("PRAGMA table_info(`nu_projects`)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pCols as $pc) {
                    if (($pc['name'] ?? '') === 'project_is_public_test') { $hasPublicCol = true; break; }
                }
            } else {
                $pCols = $pdo->query("DESCRIBE `nu_projects`")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pCols as $pc) {
                    if (($pc['Field'] ?? '') === 'project_is_public_test') { $hasPublicCol = true; break; }
                }
            }

            if (!$hasPublicCol) {
                if ($driver === 'sqlite') {
                    $pdo->exec("ALTER TABLE `nu_projects` ADD COLUMN `project_is_public_test` INTEGER NOT NULL DEFAULT 0");
                    $pdo->exec("ALTER TABLE `nu_projects` ADD COLUMN `project_share_token` TEXT NULL");
                    $pdo->exec("ALTER TABLE `nu_projects` ADD COLUMN `project_share_mode` TEXT DEFAULT 'guest'");
                } else {
                    $pdo->exec("ALTER TABLE `nu_projects` ADD COLUMN `project_is_public_test` TINYINT(1) NOT NULL DEFAULT 0");
                    $pdo->exec("ALTER TABLE `nu_projects` ADD COLUMN `project_share_token` VARCHAR(64) NULL");
                    $pdo->exec("ALTER TABLE `nu_projects` ADD COLUMN `project_share_mode` VARCHAR(20) NOT NULL DEFAULT 'guest'");
                    try {
                        $pdo->exec("ALTER TABLE `nu_projects` ADD KEY `idx_project_share_token` (`project_share_token`)");
                    } catch (Exception $e) {}
                }
            }
        } catch (Exception $e) {}

        // Ensure nu_project_versions table
        $hasProjectVersions = false;
        try {
            $hasProjectVersions = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_project_versions'" : "SHOW TABLES LIKE 'nu_project_versions'")->fetch();
        } catch (Exception $e) {}

        if (!$hasProjectVersions) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_versions` (
                    `pv_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `pv_project_id` INTEGER NOT NULL,
                    `pv_version_tag` TEXT NOT NULL,
                    `pv_description` TEXT NULL,
                    `pv_snapshot_data` TEXT NOT NULL,
                    `pv_created_by` INTEGER NULL,
                    `pv_created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_versions` (
                    `pv_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `pv_project_id` INT UNSIGNED NOT NULL,
                    `pv_version_tag` VARCHAR(50) NOT NULL,
                    `pv_description` TEXT NULL,
                    `pv_snapshot_data` MEDIUMTEXT NOT NULL,
                    `pv_created_by` INT NULL,
                    `pv_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`pv_id`),
                    KEY `idx_pv_project` (`pv_project_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        }

        // Seed default project
        $stmtProj = $pdo->prepare("SELECT COUNT(*) FROM `nu_projects` WHERE `project_code` = 'default'");
        $stmtProj->execute();
        if ((int)$stmtProj->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO `nu_projects` (`project_code`, `project_name`, `project_description`, `project_active`, `project_is_default`) VALUES ('default', 'Default Project', 'Default system project created during migration.', 1, 1)")->execute();
        }

        // Ensure nu_project_members table
        $hasMembers = false;
        try {
            $hasMembers = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_project_members'" : "SHOW TABLES LIKE 'nu_project_members'")->fetch();
        } catch (Exception $e) {}

        if (!$hasMembers) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_members` (
                    `pm_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `pm_project_id` INTEGER NOT NULL,
                    `pm_user_id` INTEGER NOT NULL,
                    `pm_role` TEXT NOT NULL DEFAULT 'member'
                )");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_members` (
                    `pm_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `pm_project_id` INT UNSIGNED NOT NULL,
                    `pm_user_id` INT NOT NULL,
                    `pm_role` VARCHAR(30) NOT NULL DEFAULT 'member',
                    PRIMARY KEY (`pm_id`),
                    UNIQUE KEY `uq_project_user` (`pm_project_id`, `pm_user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        }

        // Ensure nu_project_tables registry table
        $hasProjectTables = false;
        try {
            $hasProjectTables = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_project_tables'" : "SHOW TABLES LIKE 'nu_project_tables'")->fetch();
        } catch (Exception $e) {}

        if (!$hasProjectTables) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_tables` (
                    `pt_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `project_id` INTEGER NOT NULL,
                    `table_name` TEXT NOT NULL UNIQUE,
                    `form_code` TEXT NULL
                )");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_tables` (
                    `pt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `project_id` INT UNSIGNED NOT NULL,
                    `table_name` VARCHAR(64) NOT NULL,
                    `form_code` VARCHAR(50) NULL,
                    PRIMARY KEY (`pt_id`),
                    UNIQUE KEY `uq_pt_table` (`table_name`),
                    KEY `idx_pt_project` (`project_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        }

        // Ensure project_id column on metadata tables
        $metaTables = ['nu_forms', 'nu_reports', 'nu_queries', 'nu_procedures', 'nu_menus', 'nu_workflows', 'nu_workflow_stages', 'nu_workflow_transitions', 'nu_form_versions'];
        foreach ($metaTables as $mTable) {
            try {
                $hasTbl = false;
                if ($driver === 'sqlite') {
                    $hasTbl = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$mTable}'")->fetch();
                } else {
                    $hasTbl = (bool)$pdo->query("SHOW TABLES LIKE '{$mTable}'")->fetch();
                }

                if (!$hasTbl) {
                    continue;
                }

                $hasCol = false;
                if ($driver === 'sqlite') {
                    $cols = $pdo->query("PRAGMA table_info(`{$mTable}`)")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($cols as $c) {
                        if (($c['name'] ?? '') === 'project_id') { $hasCol = true; break; }
                    }
                } else {
                    $cols = $pdo->query("DESCRIBE `{$mTable}`")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($cols as $c) {
                        if (($c['Field'] ?? '') === 'project_id') { $hasCol = true; break; }
                    }
                }

                if (!$hasCol) {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `{$mTable}` ADD COLUMN `project_id` INTEGER DEFAULT 1");
                    } else {
                        $pdo->exec("ALTER TABLE `{$mTable}` ADD COLUMN `project_id` INT UNSIGNED NOT NULL DEFAULT 1");
                        try {
                            $pdo->exec("ALTER TABLE `{$mTable}` ADD KEY `idx_{$mTable}_project` (`project_id`)");
                        } catch (Exception $e) {}
                    }
                }
                $pdo->exec("UPDATE `{$mTable}` SET `project_id` = 1 WHERE `project_id` IS NULL OR `project_id` = 0");
            } catch (Exception $e) {}
        }
    }
};
