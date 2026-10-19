<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only\n"); }
/**
 * Migration: Create nu_role_form_permissions table and seed nu_roles with globeadmin
 * Run once: GET /api/migrate-roles.php
 */
require_once dirname(__DIR__) . '/core/Database.php';

$db = NuDatabase::getInstance();
$pdo = $db->getPdo();

$steps = [];

// 1. nu_roles — ensure role_is_system column exists
try {
    $pdo->exec("ALTER TABLE nu_roles ADD COLUMN IF NOT EXISTS role_is_system TINYINT(1) NOT NULL DEFAULT 0");
    $steps[] = 'nu_roles.role_is_system column ensured';
} catch (Exception $e) {
    $steps[] = 'nu_roles.role_is_system: ' . $e->getMessage();
}

// 2. Protect globeadmin row
try {
    $pdo->exec("UPDATE nu_roles SET role_is_system = 1 WHERE role_code = 'globeadmin'");
    $steps[] = 'globeadmin marked as system role';
} catch (Exception $e) {
    $steps[] = 'globeadmin protect: ' . $e->getMessage();
}

// 3. nu_role_form_permissions
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nu_role_form_permissions (
            rfp_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rfp_role_code VARCHAR(80)  NOT NULL,
            rfp_form_code VARCHAR(120) NOT NULL COMMENT '* = wildcard / all forms',
            rfp_can_view   TINYINT(1) NOT NULL DEFAULT 0,
            rfp_can_add    TINYINT(1) NOT NULL DEFAULT 0,
            rfp_can_edit   TINYINT(1) NOT NULL DEFAULT 0,
            rfp_can_delete TINYINT(1) NOT NULL DEFAULT 0,
            rfp_can_export TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_role_form (rfp_role_code, rfp_form_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $steps[] = 'nu_role_form_permissions table created';
} catch (Exception $e) {
    $steps[] = 'nu_role_form_permissions: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'steps' => $steps]);
