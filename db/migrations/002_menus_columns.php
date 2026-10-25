<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasMenus = false;
        try {
            if ($driver === 'sqlite') {
                $hasMenus = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='nu_menus'")->fetch();
            } else {
                $hasMenus = (bool)$pdo->query("SHOW TABLES LIKE 'nu_menus'")->fetch();
            }
        } catch (Exception $e) {}

        if (!$hasMenus) {
            return;
        }

        $columns = [];
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(`nu_menus`)");
            if ($stmt) {
                while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $columns[] = strtolower($col['name']);
                }
            }
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM `nu_menus`");
            if ($stmt) {
                while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $columns[] = strtolower($col['Field']);
                }
            }
        }

        if (!in_array('menu_role_access', $columns, true)) {
            $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_role_access` VARCHAR(512) DEFAULT NULL");
        }
        if (!in_array('menu_open_mode', $columns, true)) {
            $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_open_mode` VARCHAR(30) NOT NULL DEFAULT 'inline|browse'");
        }
        if (!in_array('menu_browse_mode', $columns, true)) {
            $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_browse_mode` VARCHAR(10) NOT NULL DEFAULT 'inline'");
        }
        if (!in_array('menu_preview_mode', $columns, true)) {
            $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_preview_mode` VARCHAR(10) NOT NULL DEFAULT 'inline'");
        }
        if (!in_array('menu_default_view', $columns, true)) {
            $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_default_view` VARCHAR(10) NOT NULL DEFAULT 'browse'");
        }

        if ($driver !== 'sqlite') {
            try {
                $pdo->exec("ALTER TABLE `nu_menus` MODIFY COLUMN `menu_type` VARCHAR(30) NOT NULL DEFAULT 'form'");
            } catch (Exception $ex) {}
        }
    }
};
