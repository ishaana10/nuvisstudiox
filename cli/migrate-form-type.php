<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only\n"); }
/**
 * Migration: Add form_type column to nu_forms
 * Run once via browser: /api/migrate-form-type.php
 */
require_once dirname(__DIR__) . '/core/module_bootstrap.php';

$db  = NuDatabase::getInstance();
$pdo = $db->getPdo();

$results = [];

// 1. Add form_type column if missing
$cols = $pdo->query("SHOW COLUMNS FROM nu_forms LIKE 'form_type'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE nu_forms ADD COLUMN form_type VARCHAR(20) NOT NULL DEFAULT 'main' AFTER form_code");
    $results[] = "✅ Added column form_type VARCHAR(20) DEFAULT 'main'";
} else {
    $results[] = "ℹ️  Column form_type already exists — skipped";
}

// 2. Set any NULL / empty values to 'main'
$updated = $pdo->exec("UPDATE nu_forms SET form_type = 'main' WHERE form_type IS NULL OR form_type = ''");
$results[] = "✅ Backfilled {$updated} rows with form_type = 'main'";

header('Content-Type: text/plain');
echo implode("\n", $results) . "\nDone.\n";
