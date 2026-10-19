<?php
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only
"); }
/**
 * One-time migration: adds missing columns to nu_forms.
 * Call once via browser: /api/migrate-nu-forms.php
 * Safe to run multiple times (uses IF NOT EXISTS pattern via SHOW COLUMNS).
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

    // Get existing columns
    $existing = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `nu_forms`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[] = $row['Field'];
    }

    $needed = [
        'form_js_before_save'       => 'TEXT',
        'form_js_after_save'        => 'TEXT',
        'form_custom_php'           => 'TEXT',
        'form_custom_css'           => 'TEXT',
        'browse_sql'                => 'TEXT',
        'browse_columns'            => 'VARCHAR(1000)',
        'browse_search_enabled'     => 'TINYINT(1) DEFAULT 0',
        'browse_search_placeholder' => 'VARCHAR(255)',
        'browse_search_fields'      => 'VARCHAR(500)',
        'browse_page_size'          => 'INT DEFAULT 20',
        'browse_default_sort'       => 'VARCHAR(255)',
    ];

    $added = [];
    foreach ($needed as $col => $type) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE `nu_forms` ADD COLUMN `{$col}` {$type}");
            $added[] = $col;
        }
    }

    echo json_encode([
        'success' => true,
        'added'   => $added,
        'message' => $added ? 'Added: ' . implode(', ', $added) : 'All columns already exist — nothing to do.'
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
