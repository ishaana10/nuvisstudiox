<?php
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only
"); }
/**
 * One-time migration: creates nu_menus table and ensures all required columns exist.
 * Call once via browser: /api/migrate-nu-menus.php
 * Safe to run multiple times (idempotent — uses SHOW TABLES + SHOW COLUMNS checks).
 */
header('Content-Type: application/json');

try {
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/core/Database.php';
    require_once dirname(__DIR__) . '/core/Auth.php';

    $auth = NuAuth::getInstance();
    if (!$auth->checkAuth()) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $db  = NuDatabase::getInstance();
    $pdo = $db->getConnection();

    $results = [];

    // ── Step 1: create table if it does not exist ─────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `nu_menus` (
            `menu_id`        INT          NOT NULL AUTO_INCREMENT,
            `menu_label`     VARCHAR(255) NOT NULL DEFAULT '',
            `menu_type`      VARCHAR(50)  NOT NULL DEFAULT 'form'
                                COMMENT 'form | report | query | url | group | divider',
            `menu_target`    VARCHAR(500) NOT NULL DEFAULT ''
                                COMMENT 'form_code, report_code, query_code, or URL',
            `menu_parent_id` INT          NOT NULL DEFAULT 0
                                COMMENT '0 = top-level; >0 = child of that menu_id',
            `menu_order`     INT          NOT NULL DEFAULT 0,
            `menu_roles`     VARCHAR(1000) NOT NULL DEFAULT ''
                                COMMENT 'Comma-separated role codes; empty = all roles',
            `menu_role_access` VARCHAR(512) DEFAULT NULL,
            `menu_active`    TINYINT(1)   NOT NULL DEFAULT 1,
            `menu_icon`      VARCHAR(100) NOT NULL DEFAULT '☰',
            `menu_created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`menu_id`),
            KEY `idx_menu_parent` (`menu_parent_id`),
            KEY `idx_menu_order`  (`menu_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $results[] = 'nu_menus table ensured (created or already existed)';

    // ── Step 2: add any columns introduced after initial creation ─────────
    $existing = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `nu_menus`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[] = $row['Field'];
    }

    // Definition of every column the API layer expects.
    // Add new columns here in future migrations — they will be auto-applied.
    $needed = [
        'menu_label'      => "VARCHAR(255)  NOT NULL DEFAULT ''",
        'menu_type'       => "VARCHAR(50)   NOT NULL DEFAULT 'form'",
        'menu_target'     => "VARCHAR(500)  NOT NULL DEFAULT ''",
        'menu_parent_id'  => 'INT           NOT NULL DEFAULT 0',
        'menu_order'      => 'INT           NOT NULL DEFAULT 0',
        'menu_roles'      => "VARCHAR(1000) NOT NULL DEFAULT ''",
        'menu_role_access' => "VARCHAR(512)  DEFAULT NULL",
        'menu_active'     => 'TINYINT(1)    NOT NULL DEFAULT 1',
        'menu_icon'       => "VARCHAR(100)  NOT NULL DEFAULT '☰'",
        'menu_created_at' => 'TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    $added = [];
    foreach ($needed as $col => $typedef) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `{$col}` {$typedef}");
            $added[] = $col;
        }
    }

    if ($added) {
        $results[] = 'Added missing columns: ' . implode(', ', $added);
    } else {
        $results[] = 'All columns already present — nothing to alter.';
    }

    // ── Self-healing: Ensure targets for email_settings and report_dashboards are set ──
    try {
        $db->query("UPDATE nu_menus SET menu_target = 'email_settings' WHERE menu_label = 'email_settings' AND (menu_target IS NULL OR menu_target = '')");
        $db->query("UPDATE nu_menus SET menu_target = 'report_dashboards' WHERE menu_label = 'report_dashboards' AND (menu_target IS NULL OR menu_target = '')");
        $results[] = 'Self-healed targets for email_settings and report_dashboards';
    } catch (Throwable $ignored) {}

    // ── Step 3: ensure indexes exist (harmless if already present) ────────
    $indexes = [];
    $idxStmt = $pdo->query("SHOW INDEX FROM `nu_menus`");
    while ($row = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
        $indexes[] = $row['Key_name'];
    }
    $wantedIndexes = [
        'idx_menu_parent' => 'menu_parent_id',
        'idx_menu_order'  => 'menu_order',
    ];
    foreach ($wantedIndexes as $idxName => $colName) {
        if (!in_array($idxName, $indexes, true)) {
            $pdo->exec("ALTER TABLE `nu_menus` ADD INDEX `{$idxName}` (`{$colName}`)");
            $results[] = "Added index: {$idxName}";
        }
    }

    echo json_encode([
        'success' => true,
        'steps'   => $results,
        'message' => 'Migration complete.',
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
