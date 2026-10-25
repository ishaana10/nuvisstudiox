<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only\n"); }
// Nuvis StudioX - Migration Tool from nuBuilder Forte 4.9
// Run this once after installing Nuvis StudioX

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';

$db = NuDatabase::getInstance();
$sourceDb = 'nubuilder_forte'; // Change to your current Nuvis StudioX database name

echo "Starting migration from Nuvis StudioX Forte 4.9...\n";

// 1. Migrate Users
echo "Migrating users...\n";
$users = $db->fetchAll("SELECT * FROM {$sourceDb}.zzzzsys_user");
foreach ($users as $u) {
    try {
        $db->insert('nu_users', [
            'usr_username' => $u['sus_login_name'],
            'usr_password' => $u['sus_password'], // Re-hash recommended
            'usr_email' => $u['sus_email'] ?? null,
            'usr_role' => $u['sus_access'] === 'globeadmin' ? 'globeadmin' : 'user',
            'usr_active' => 1
        ]);
    } catch (Exception $e) {
        echo "Skipped user: " . $u['sus_login_name'] . " (may already exist)\n";
    }
}

// 2. Migrate Forms metadata
echo "Migrating forms...\n";
$forms = $db->fetchAll("SELECT * FROM {$sourceDb}.zzzzsys_form");
foreach ($forms as $f) {
    try {
        $db->insert('nu_forms', [
            'form_code' => $f['sfo_code'],
            'form_name' => $f['sfo_description'] ?? $f['sfo_code'],
            'form_table' => $f['sfo_table'] ?? null,
            'form_layout' => json_encode(['migrated' => true, 'original_id' => $f['zzzzsys_form_id']]),
            'form_settings' => '{}',
            'form_active' => 1
        ]);
    } catch (Exception $e) {
        echo "Skipped form: " . $f['sfo_code'] . "\n";
    }
}

// 3. Migrate Reports
echo "Migrating reports...\n";
$reports = $db->fetchAll("SELECT * FROM {$sourceDb}.zzzzsys_report");
foreach ($reports as $r) {
    try {
        $db->insert('nu_reports', [
            'report_code' => $r['sre_code'],
            'report_name' => $r['sre_description'] ?? $r['sre_code'],
            'report_type' => 'table',
            'report_sql' => $r['sre_sql'] ?? null,
            'report_columns' => '[]',
            'report_settings' => '{}',
            'report_active' => 1
        ]);
    } catch (Exception $e) {
        echo "Skipped report: " . $r['sre_code'] . "\n";
    }
}

echo "Migration complete!\n";
echo "IMPORTANT:\n";
echo "1. Review migrated data\n";
echo "2. Re-hash passwords for security\n";
echo "3. Test all forms and reports\n";
echo "4. Update config.php settings\n";
