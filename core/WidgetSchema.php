<?php
declare(strict_types=1);
/**
 * NuWidgetSchema — idempotent schema upgrade for dashboard widgets (v2).
 *
 * Adds widget_key / widget_locked / widget_refresh, the per-user prefs and
 * settings tables, removes exact duplicate role widgets (re-running install.sql
 * used to insert the seeds again) and finally adds the unique key that makes
 * `INSERT IGNORE` seeds a no-op on re-runs.
 *
 * Works on MySQL/MariaDB (production) and SQLite (unit tests).
 */
class NuWidgetSchema {
    private const T_WIDGETS  = 'nu_dashboard_widgets';
    private const T_PREFS    = 'nu_dashboard_user_prefs';
    private const T_SETTINGS = 'nu_dashboard_user_settings';
    private const UNIQUE_KEY = 'uq_role_widget';

    /** Seeded widgets from install.sql: role => [title => widget_key]. */
    public const SEEDED_KEYS = [
        'user' => [
            'Available Forms'    => 'user.available_forms',
            'Available Reports'  => 'user.available_reports',
            'Quick Links'        => 'user.quick_links',
            'My Recent Activity' => 'user.my_recent_activity',
        ],
        'manager' => [
            'Total Users'        => 'manager.total_users',
            'Active Forms'       => 'manager.active_forms',
            'Activity This Week' => 'manager.activity_this_week',
            'Actions Breakdown'  => 'manager.actions_breakdown',
        ],
    ];

    public static function ensure(NuDatabase $db): void {
        self::createUserTables($db);
        if (!self::tableExists($db, self::T_WIDGETS)) {
            return;
        }
        $have = self::columns($db, self::T_WIDGETS);
        $add = [
            'widget_key'     => 'VARCHAR(64) NULL',
            'widget_locked'  => 'TINYINT(1) NOT NULL DEFAULT 0',
            'widget_refresh' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        ];
        foreach ($add as $col => $ddl) {
            if (!in_array($col, $have, true)) {
                if (self::isSqlite($db)) {
                    $ddl = str_replace(['TINYINT(1)', 'SMALLINT UNSIGNED', 'VARCHAR(64)'], ['INTEGER', 'INTEGER', 'TEXT'], $ddl);
                }
                $db->exec('ALTER TABLE ' . self::T_WIDGETS . " ADD COLUMN {$col} {$ddl}");
            }
        }
        self::dedupeRoleWidgets($db);
        self::backfillKeys($db);
        self::ensureUniqueKey($db);
    }

    /**
     * Delete exact duplicate role-level widgets (same role, type, title, config,
     * active flag), keeping the lowest widget_id of each group. Personal rows and
     * rows whose config differs are never touched. Returns rows removed.
     */
    public static function dedupeRoleWidgets(NuDatabase $db): int {
        $t = self::T_WIDGETS;
        // Derived table so the same statement also runs on MySQL (error 1093).
        $stmt = $db->query(
            "DELETE FROM {$t}
              WHERE widget_user_id IS NULL
                AND widget_id NOT IN (
                    SELECT keep_id FROM (
                        SELECT MIN(widget_id) AS keep_id FROM {$t}
                         WHERE widget_user_id IS NULL
                         GROUP BY widget_role, widget_type, widget_title, CAST(widget_config AS CHAR), widget_active
                    ) AS k
                )"
        );
        return (int)$stmt->rowCount();
    }

    private static function backfillKeys(NuDatabase $db): void {
        $t = self::T_WIDGETS;
        foreach (self::SEEDED_KEYS as $role => $titles) {
            foreach ($titles as $title => $key) {
                if ($db->fetchOne("SELECT 1 FROM {$t} WHERE widget_role = ? AND widget_key = ?", [$role, $key])) {
                    continue;
                }
                $db->query(
                    "UPDATE {$t} SET widget_key = ?
                      WHERE widget_user_id IS NULL AND widget_key IS NULL
                        AND widget_id = (
                            SELECT m FROM (
                                SELECT MIN(widget_id) AS m FROM {$t}
                                 WHERE widget_user_id IS NULL AND widget_role = ? AND widget_title = ?
                            ) AS t
                        )",
                    [$key, $role, $title]
                );
            }
        }
    }

    private static function ensureUniqueKey(NuDatabase $db): void {
        $t = self::T_WIDGETS;
        $k = self::UNIQUE_KEY;
        if (self::isSqlite($db)) {
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$k} ON {$t} (widget_role, widget_key)");
            return;
        }
        $has = $db->fetchOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
            [$t, $k]
        );
        if (!$has) {
            $db->exec("ALTER TABLE {$t} ADD UNIQUE KEY {$k} (widget_role, widget_key)");
        }
    }

    private static function createUserTables(NuDatabase $db): void {
        if (self::isSqlite($db)) {
            $db->exec('CREATE TABLE IF NOT EXISTS ' . self::T_PREFS . ' (
                pref_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL, widget_id INTEGER NOT NULL,
                hidden INTEGER NOT NULL DEFAULT 0, position INTEGER NULL,
                width INTEGER NULL, height INTEGER NULL, accent TEXT NULL,
                updated_at TEXT NULL,
                UNIQUE (user_id, widget_id))');
            $db->exec('CREATE TABLE IF NOT EXISTS ' . self::T_SETTINGS . ' (
                user_id INTEGER PRIMARY KEY, settings TEXT NOT NULL, updated_at TEXT NULL)');
            return;
        }
        $db->exec('CREATE TABLE IF NOT EXISTS `' . self::T_PREFS . '` (
            `pref_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `widget_id` INT UNSIGNED NOT NULL,
            `hidden` TINYINT(1) NOT NULL DEFAULT 0,
            `position` SMALLINT NULL,
            `width` TINYINT UNSIGNED NULL,
            `height` TINYINT UNSIGNED NULL,
            `accent` VARCHAR(20) NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`pref_id`),
            UNIQUE KEY `uq_user_widget` (`user_id`, `widget_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $db->exec('CREATE TABLE IF NOT EXISTS `' . self::T_SETTINGS . '` (
            `user_id` INT UNSIGNED NOT NULL,
            `settings` TEXT NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private static function isSqlite(NuDatabase $db): bool {
        return $db->getDriverName() === 'sqlite';
    }

    private static function tableExists(NuDatabase $db, string $table): bool {
        if (self::isSqlite($db)) {
            return (bool)$db->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        }
        return (bool)$db->fetchOne(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        );
    }

    /** @return string[] column names */
    private static function columns(NuDatabase $db, string $table): array {
        if (self::isSqlite($db)) {
            return array_column($db->fetchAll("PRAGMA table_info({$table})"), 'name');
        }
        return array_column($db->fetchAll(
            'SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ), 'name');
    }
}
