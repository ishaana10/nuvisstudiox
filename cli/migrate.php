<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only\n"); }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/MigrationRunner.php';

echo "Running pending database migrations...\n";

try {
    $db = NuDatabase::getInstance();
    $runner = new MigrationRunner($db->getPdo());
    $executed = $runner->migrate();

    if (empty($executed)) {
        echo "No pending migrations to apply.\n";
    } else {
        echo "Successfully applied " . count($executed) . " migration(s):\n";
        foreach ($executed as $ver) {
            echo "  - {$ver}\n";
        }
    }
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Migration process finished.\n";
