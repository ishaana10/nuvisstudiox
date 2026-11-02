<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuCustomerRequests;
use NuDatabase;
use PDO;

require_once __DIR__ . '/../../core/CustomerRequests.php';

/**
 * A tiny in-memory NuDatabase stand-in. CustomerRequests only type-hints
 * NuDatabase and uses a small, well-defined subset of its methods, so this
 * lets us exercise schema + provisioning against real SQLite without the
 * full platform bootstrap. The class is only declared if the real one is not
 * already loaded by another test.
 */
if (!class_exists('\NuDatabase')) {
    eval('
    class NuDatabase
    {
        public PDO $pdo;
        public function __construct() { $this->pdo = new PDO("sqlite::memory:"); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
        public function getDriverName(): string { return "sqlite"; }
        public function getPdo(): PDO { return $this->pdo; }
        public function exec(string $sql) { return $this->pdo->exec($sql); }
        public function query($sql, $p = []) { $st = $this->pdo->prepare($sql); $st->execute($p); return $st; }
        public function fetchOne($sql, $p = []) { $st = $this->pdo->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC); return $r === false ? null : $r; }
        public function fetchAll($sql, $p = []) { $st = $this->pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
        public function insert($t, $d) { $k = array_keys($d); $st = $this->pdo->prepare("INSERT INTO `" . $t . "` (`" . implode("`,`", $k) . "`) VALUES (" . implode(",", array_fill(0, count($k), "?")) . ")"); $st->execute(array_values($d)); return (int)$this->pdo->lastInsertId(); }
        public function lastInsertId() { return (int)$this->pdo->lastInsertId(); }
        public function update($t, $d, $w, $wp = []) { $set = implode(",", array_map(fn($c) => "`" . $c . "`=?", array_keys($d))); $st = $this->pdo->prepare("UPDATE `" . $t . "` SET " . $set . " WHERE " . $w); $st->execute(array_merge(array_values($d), $wp)); }
    }
    ');
}

final class CustomerRequestsTest extends TestCase
{
    /* ── Pure helpers ─────────────────────────────────────────────────────── */

    public function testTemplatesAreWellFormed(): void
    {
        $templates = NuCustomerRequests::templates();
        $this->assertArrayHasKey('support', $templates);
        $this->assertArrayHasKey('service', $templates);
        $this->assertArrayHasKey('contact', $templates);
        $this->assertArrayHasKey('bug', $templates);

        foreach ($templates as $key => $t) {
            $this->assertNotEmpty($t['fields'], "$key has fields");
            $this->assertNotEmpty($t['stages'], "$key has stages");
            // exactly one start stage
            $starts = array_filter($t['stages'], static fn($s) => !empty($s['start']));
            $this->assertCount(1, $starts, "$key has exactly one start stage");
            // at least one end stage
            $ends = array_filter($t['stages'], static fn($s) => !empty($s['end']));
            $this->assertNotEmpty($ends, "$key has an end stage");
        }
    }

    public function testTemplateSummariesMatchTemplates(): void
    {
        $summaries = NuCustomerRequests::templateSummaries();
        $this->assertCount(count(NuCustomerRequests::templates()), $summaries);
        $support = array_values(array_filter($summaries, static fn($s) => $s['key'] === 'support'))[0];
        $this->assertSame(7, $support['field_count']);
        $this->assertSame(6, $support['stage_count']);
    }

    public function testBuildFormLayout(): void
    {
        $fields = [
            ['name' => 'customer_name', 'type' => 'text', 'label' => 'Name', 'required' => true],
            ['name' => 'category', 'type' => 'select', 'label' => 'Category', 'options' => ['A', 'B']],
        ];
        $layout = NuCustomerRequests::buildFormLayout($fields);
        $this->assertCount(2, $layout);
        $this->assertSame('customer_name', $layout[0]['name']);
        $this->assertTrue($layout[0]['required']);
        $this->assertSame(0, $layout[0]['row_index']);
        $this->assertSame(1, $layout[1]['row_index']);
        // select options expand to {value,label}
        $this->assertSame([['value' => 'A', 'label' => 'A'], ['value' => 'B', 'label' => 'B']], $layout[1]['options']);
    }

    public function testSlugify(): void
    {
        $this->assertSame('my-help-desk', NuCustomerRequests::slugify('My Help Desk!'));
        $this->assertSame('my_help_desk', NuCustomerRequests::slugify('My Help Desk!', '_'));
        $this->assertSame('requests', NuCustomerRequests::slugify('   ')); // empty falls back
    }

    public function testUuidFormat(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            NuCustomerRequests::uuid()
        );
    }

    /* ── Schema + provisioning (SQLite) ───────────────────────────────────── */

    private function freshDb(): NuDatabase
    {
        $db = NuDatabase::getInstance();
        $db->exec('DROP TABLE IF EXISTS nu_email_templates');
        $db->exec('DROP TABLE IF EXISTS nu_forms');
        $db->exec('DROP TABLE IF EXISTS nu_workflows');
        $db->exec('DROP TABLE IF EXISTS nu_workflow_stages');
        $db->exec('DROP TABLE IF EXISTS nu_workflow_transitions');
        $db->exec('DROP TABLE IF EXISTS nu_form_shares');
        $db->exec('DROP TABLE IF EXISTS nu_cr_configs');
        $db->exec('DROP TABLE IF EXISTS nu_cr_notes');

        $db->exec('CREATE TABLE nu_email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT UNIQUE, description TEXT, subject TEXT, body TEXT, is_active INT DEFAULT 1, created_at TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE nu_forms (form_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INT, form_code TEXT, form_type TEXT, form_name TEXT, form_table TEXT, form_description TEXT, form_layout TEXT, form_active INT, form_pk_type TEXT, form_table_mode TEXT, form_created_by INT, form_created_at TEXT, form_updated_at TEXT)');
        $db->exec('CREATE TABLE nu_workflows (wf_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INT, wf_code TEXT, wf_name TEXT, wf_description TEXT, wf_form_code TEXT, wf_active INT, wf_created_by INT)');
        $db->exec('CREATE TABLE nu_workflow_stages (wfs_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INT, wfs_wf_id INT, wfs_code TEXT, wfs_name TEXT, wfs_color TEXT, wfs_is_start INT, wfs_is_end INT, wfs_order INT, wfs_sla_hours INT)');
        $db->exec('CREATE TABLE nu_workflow_transitions (wft_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INT, wft_wf_id INT, wft_from_id INT, wft_to_id INT, wft_action TEXT, wft_label TEXT, wft_options TEXT)');
        $db->exec('CREATE TABLE nu_form_shares (fs_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INT, fs_form_code TEXT, fs_token TEXT, fs_secret TEXT, fs_enabled INT, fs_title TEXT, fs_submit_label TEXT, fs_success_message TEXT, fs_notify_email TEXT, fs_confirm_email INT, fs_email_field TEXT, fs_confirm_message TEXT, fs_created_by TEXT)');
        $db->exec('DROP TABLE IF EXISTS nu_workflow_instances');
        $db->exec('CREATE TABLE nu_workflow_instances (wfi_id INTEGER PRIMARY KEY AUTOINCREMENT, wfi_wf_id INT, wfi_stage_id INT, wfi_record_table TEXT, wfi_record_id TEXT, wfi_status TEXT DEFAULT "active", wfi_started_by INT, wfi_started_at DATETIME DEFAULT CURRENT_TIMESTAMP, wfi_completed_at DATETIME, wfi_meta TEXT)');
        // reset the one-time schema guard between tests
        $ref = new \ReflectionClass(NuCustomerRequests::class);
        $prop = $ref->getProperty('schemaChecked');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
        return $db;
    }

    public function testEnsureSchemaCreatesTablesAndSeedsEmail(): void
    {
        $db = $this->freshDb();
        NuCustomerRequests::ensureSchema($db);
        $this->assertNotEmpty($db->fetchAll("PRAGMA table_info(nu_cr_configs)"));
        $this->assertNotEmpty($db->fetchAll("PRAGMA table_info(nu_cr_notes)"));
        $slugs = array_column($db->fetchAll('SELECT slug FROM nu_email_templates'), 'slug');
        $this->assertContains('cr_request_received', $slugs);
        $this->assertContains('cr_status_changed', $slugs);
    }

    public function testProvisionCreatesFullChannel(): void
    {
        if (!class_exists('ProjectContext')) {
            eval('class ProjectContext { public static function getId(): int { return 1; } public static function registerProjectTable(int $p, string $t, ?string $f = null): bool { return true; } }');
        }
        $db = $this->freshDb();
        $cfg = NuCustomerRequests::provision($db, 1, 7, [
            'name' => 'Help Desk', 'template' => 'support', 'notify_email' => 'ops@x.com', 'public_enabled' => 1,
        ]);

        $this->assertSame('help-desk', $cfg['cr_code']);
        $this->assertSame('TKT-', $cfg['cr_reference_prefix']);
        $this->assertNotEmpty($cfg['cr_workflow_code']);

        // backing table exists with the template's fields
        $cols = array_column($db->fetchAll("PRAGMA table_info(`{$cfg['cr_table']}`)"), 'name');
        $this->assertContains('customer_name', $cols);
        $this->assertContains('subject', $cols);

        // workflow has all stages and a sensible set of transitions
        $this->assertCount(6, $cfg['stages']);
        $trans = $db->fetchAll('SELECT wft_action FROM nu_workflow_transitions');
        $this->assertNotEmpty($trans);
        $this->assertContains('reject', array_column($trans, 'wft_action'));

        // public share enabled
        $share = $db->fetchOne('SELECT fs_enabled, fs_notify_email FROM nu_form_shares WHERE fs_form_code = ?', [$cfg['cr_form_code']]);
        $this->assertSame(1, (int)$share['fs_enabled']);
        $this->assertSame('ops@x.com', $share['fs_notify_email']);

        // a second channel with the same name gets a unique code + table
        $cfg2 = NuCustomerRequests::provision($db, 1, 7, ['name' => 'Help Desk', 'template' => 'contact']);
        $this->assertNotSame($cfg['cr_code'], $cfg2['cr_code']);
        $this->assertNotSame($cfg['cr_table'], $cfg2['cr_table']);

        $this->assertCount(2, NuCustomerRequests::listConfigs($db, 1));
    }

    public function testCustomFormTableWithoutRequestIdWorksInApi(): void
    {
        $db = $this->freshDb();
        // Create a custom table that only has 'id' (UUID or int) and no 'request_id' column
        $db->exec('DROP TABLE IF EXISTS test_custom_customer_requests');
        $db->exec('CREATE TABLE test_custom_customer_requests (
            id TEXT PRIMARY KEY,
            cust_name TEXT NOT NULL,
            cust_email TEXT,
            issue TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $db->exec("INSERT INTO test_custom_customer_requests (id, cust_name, cust_email, issue, created_at) VALUES ('cust-1', 'Alice', 'alice@test.com', 'Broken pipe', '2026-03-01 10:00:00')");
        $db->exec("INSERT INTO test_custom_customer_requests (id, cust_name, cust_email, issue, created_at) VALUES ('cust-2', 'Bob', 'bob@test.com', 'Fumigation needed', '2026-03-02 11:00:00')");

        // Insert custom form
        $db->exec("INSERT INTO nu_forms (project_id, form_code, form_type, form_name, form_table, form_layout, form_active, form_pk_type, form_table_mode) VALUES (
            1, 'frm_custom_requests', 'main', 'Custom Requests Form', 'test_custom_customer_requests',
            '[{\"type\": \"row\", \"children\": [{\"name\": \"cust_name\", \"label\": \"Customer Name\", \"type\": \"text\"}, {\"name\": \"issue\", \"label\": \"Issue\", \"type\": \"textarea\"}]}]',
            1, 'uuid', 'existing'
        )");

        // Provision / attach channel using existing form
        $cfg = NuCustomerRequests::provision($db, 1, 1, [
            'name' => 'Custom Requests Channel',
            'template' => 'existing',
            'form_code' => 'frm_custom_requests',
            'customer_name_field' => 'cust_name',
            'customer_email_field' => 'cust_email',
            'subject_field' => 'issue',
            'public_enabled' => 0,
        ]);

        $this->assertSame('test_custom_customer_requests', $cfg['cr_table']);

        // Now exercise api/customer_requests.php logic using a sub-process request simulation or direct execution
        $_SESSION['nu_user_id'] = 1;
        $_SESSION['nu_role'] = 'admin';
        $_SESSION['nu_project_id'] = 1;
        $_SESSION['nu_last_activity'] = time();

        // Run stats action simulation
        $_GET['action'] = 'stats';
        $_GET['id'] = $cfg['cr_id'];

        ob_start();
        include dirname(__DIR__, 2) . '/api/customer_requests.php';
        $output = ob_get_clean();

        $statsRes = json_decode($output, true);
        $this->assertIsArray($statsRes, 'Stats response should be valid JSON: ' . $output);
        $this->assertTrue($statsRes['success'] ?? false, 'Stats should succeed without column errors');
        $this->assertSame(2, $statsRes['stats']['total']);

        // Run board action
        $_GET['action'] = 'board';
        $_GET['id'] = $cfg['cr_id'];

        ob_start();
        include dirname(__DIR__, 2) . '/api/customer_requests.php';
        $output = ob_get_clean();

        $boardRes = json_decode($output, true);
        $this->assertIsArray($boardRes, 'Board response should be valid JSON');
        $this->assertTrue($boardRes['success'] ?? false, 'Board should succeed without column errors');
        $this->assertSame(2, $boardRes['count']);

        // Run get_config action
        $_GET['action'] = 'get_config';
        $_GET['id'] = $cfg['cr_id'];

        ob_start();
        include dirname(__DIR__, 2) . '/api/customer_requests.php';
        $output = ob_get_clean();

        $cfgRes = json_decode($output, true);
        $this->assertIsArray($cfgRes);
        $this->assertTrue($cfgRes['success'] ?? false);
        $this->assertNotEmpty($cfgRes['config']['fields']);

        // Run request detail action
        $_GET['action'] = 'request';
        $_GET['id'] = $cfg['cr_id'];
        $_GET['record'] = 'cust-1';

        ob_start();
        include dirname(__DIR__, 2) . '/api/customer_requests.php';
        $output = ob_get_clean();

        $reqRes = json_decode($output, true);
        $this->assertIsArray($reqRes);
        $this->assertTrue($reqRes['success'] ?? false, 'Request detail should succeed');
        $this->assertSame('Alice', $reqRes['request']['record']['cust_name']);
    }
}
