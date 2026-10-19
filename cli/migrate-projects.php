<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only\n"); }
// Nuvis StudioX - Migration script to add Multi-Project Support

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';

$db = NuDatabase::getInstance();
$pdo = $db->getPdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

echo "Starting Multi-Project Migration...\n";

// Helper for DDL execution
function run_ddl($pdo, $sql) {
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // Ignore duplicate column/table or index errors during migration
        if (!str_contains($e->getMessage(), 'already exists') &&
            !str_contains($e->getMessage(), 'Duplicate column') &&
            !str_contains($e->getMessage(), 'Duplicate key') &&
            !str_contains($e->getMessage(), 'duplicate column')) {
            echo "DDL Notice: " . $e->getMessage() . "\n";
        }
    }
}

// 1. Create nu_projects
if ($driver === 'sqlite') {
    run_ddl($pdo, "CREATE TABLE IF NOT EXISTS `nu_projects` (
        `project_id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `project_code` TEXT NOT NULL UNIQUE,
        `project_name` TEXT NOT NULL,
        `project_description` TEXT NULL,
        `project_settings` TEXT NULL,
        `project_active` INTEGER NOT NULL DEFAULT 1,
        `project_is_default` INTEGER NOT NULL DEFAULT 0,
        `project_owner_id` INTEGER NULL,
        `project_created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
        `project_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
    )");
} else {
    run_ddl($pdo, "CREATE TABLE IF NOT EXISTS `nu_projects` (
        `project_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `project_code` VARCHAR(50) NOT NULL,
        `project_name` VARCHAR(150) NOT NULL,
        `project_description` TEXT NULL,
        `project_settings` JSON NULL,
        `project_active` TINYINT(1) NOT NULL DEFAULT 1,
        `project_is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `project_owner_id` INT NULL,
        `project_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `project_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`project_id`),
        UNIQUE KEY `uq_project_code` (`project_code`),
        KEY `idx_project_active` (`project_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Ensure Default Project exists
$defaultProj = $db->fetchOne("SELECT * FROM nu_projects WHERE project_code = 'default'");
if (!$defaultProj) {
    $db->insert('nu_projects', [
        'project_code' => 'default',
        'project_name' => 'Default Project',
        'project_description' => 'Default system project created during migration.',
        'project_active' => 1,
        'project_is_default' => 1
    ]);
    echo "Created default project (ID 1).\n";
}

// 2. Create nu_project_members
if ($driver === 'sqlite') {
    run_ddl($pdo, "CREATE TABLE IF NOT EXISTS `nu_project_members` (
        `pm_id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `pm_project_id` INTEGER NOT NULL,
        `pm_user_id` INTEGER NOT NULL,
        `pm_role` TEXT NOT NULL DEFAULT 'member'
    )");
} else {
    run_ddl($pdo, "CREATE TABLE IF NOT EXISTS `nu_project_members` (
        `pm_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `pm_project_id` INT UNSIGNED NOT NULL,
        `pm_user_id` INT NOT NULL,
        `pm_role` VARCHAR(30) NOT NULL DEFAULT 'member',
        PRIMARY KEY (`pm_id`),
        UNIQUE KEY `uq_project_user` (`pm_project_id`, `pm_user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// 3. Create nu_project_tables
if ($driver === 'sqlite') {
    run_ddl($pdo, "CREATE TABLE IF NOT EXISTS `nu_project_tables` (
        `pt_id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `project_id` INTEGER NOT NULL,
        `table_name` TEXT NOT NULL UNIQUE,
        `form_code` TEXT NULL
    )");
} else {
    run_ddl($pdo, "CREATE TABLE IF NOT EXISTS `nu_project_tables` (
        `pt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `project_id` INT UNSIGNED NOT NULL,
        `table_name` VARCHAR(64) NOT NULL,
        `form_code` VARCHAR(50) NULL,
        PRIMARY KEY (`pt_id`),
        UNIQUE KEY `uq_pt_table` (`table_name`),
        KEY `idx_pt_project` (`project_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// 4. Helper to add project_id column to tables
$tables = ['nu_forms', 'nu_reports', 'nu_queries', 'nu_procedures', 'nu_menus', 'nu_workflows', 'nu_workflow_stages', 'nu_workflow_transitions', 'nu_report_dashboards', 'nu_form_versions'];

foreach ($tables as $tbl) {
    try {
        $hasTbl = false;
        if ($driver === 'sqlite') {
            $hasTbl = (bool)$db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$tbl]);
        } else {
            $hasTbl = (bool)$db->fetchOne("SHOW TABLES LIKE ?", [$tbl]);
        }

        if (!$hasTbl) {
            continue; // Table doesn't exist yet
        }

        $check = false;
        if ($driver === 'sqlite') {
            $cols = $db->fetchAll("PRAGMA table_info(`{$tbl}`)");
            foreach ($cols as $c) {
                if ($c['name'] === 'project_id') $check = true;
            }
        } else {
            $cols = $db->fetchAll("DESCRIBE `{$tbl}`");
            foreach ($cols as $c) {
                if ($c['Field'] === 'project_id') $check = true;
            }
        }

        if (!$check) {
            if ($driver === 'sqlite') {
                run_ddl($pdo, "ALTER TABLE `{$tbl}` ADD COLUMN `project_id` INTEGER DEFAULT 1");
            } else {
                run_ddl($pdo, "ALTER TABLE `{$tbl}` ADD COLUMN `project_id` INT UNSIGNED NOT NULL DEFAULT 1");
                run_ddl($pdo, "ALTER TABLE `{$tbl}` ADD KEY `idx_{$tbl}_project` (`project_id`)");
            }
            echo "Added project_id to {$tbl}.\n";
        }

        // Backfill null/0 values to 1
        $db->exec("UPDATE `{$tbl}` SET `project_id` = 1 WHERE `project_id` IS NULL OR `project_id` = 0");

    } catch (Exception $e) {
        echo "Error processing {$tbl}: " . $e->getMessage() . "\n";
    }
}

// Backfill form tables into nu_project_tables
try {
    $forms = $db->fetchAll("SELECT form_code, form_table, project_id FROM nu_forms WHERE form_table IS NOT NULL AND form_table != ''");
    foreach ($forms as $f) {
        $pid = (int)($f['project_id'] ?? 1);
        $tblName = trim($f['form_table']);
        if ($driver === 'sqlite') {
            $db->exec("INSERT OR IGNORE INTO nu_project_tables (project_id, table_name, form_code) VALUES ({$pid}, '{$tblName}', '{$f['form_code']}')");
        } else {
            $db->exec("INSERT IGNORE INTO nu_project_tables (project_id, table_name, form_code) VALUES ({$pid}, '{$tblName}', '{$f['form_code']}')");
        }
    }
} catch (Exception $e) {
    echo "Error registering form tables: " . $e->getMessage() . "\n";
}

echo "Multi-Project Migration completed successfully.\n";
