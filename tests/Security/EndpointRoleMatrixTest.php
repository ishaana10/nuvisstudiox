<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use Nuvis\Tests\Support\EndpointRunner;

require_once NU_ROOT . '/tests/Support/EndpointRunner.php';

/**
 * Endpoint-by-role matrix. Each data endpoint is called in its own PHP process as: anonymous, a signed-in
 * non-member, a project member whose role holds only the view flag, and globeadmin. Data belongs to project 2;
 * a second table and a saved query belong to project 3.
 */
class EndpointRoleMatrixTest extends TestCase
{
    private const MEMBER = ['nu_user_id' => '3', 'nu_role' => 'clerk', 'nu_project_id' => 2];
    private const STRANGER = ['nu_user_id' => '4', 'nu_role' => 'clerk', 'nu_project_id' => 2];
    private const GLOBE = ['nu_user_id' => '1', 'nu_role' => 'globeadmin', 'nu_project_id' => 2];

    protected function setUp(): void
    {
        $db = NuDatabase::getInstance();
        foreach (['nu_projects', 'nu_project_members', 'nu_project_tables', 'nu_forms', 'nu_role_form_permissions', 'nu_queries', 'acme_orders', 'other_data', 'nu_users', 'nu_roles', 'nu_permissions', 'nu_role_permissions'] as $t) {
            $db->exec("DROP TABLE IF EXISTS {$t}");
        }
        $db->exec("CREATE TABLE nu_projects (project_id INTEGER PRIMARY KEY AUTOINCREMENT, project_code TEXT, project_name TEXT, project_owner_id TEXT NULL,
            project_active INTEGER DEFAULT 1, project_is_default INTEGER DEFAULT 0, project_is_public_test INTEGER DEFAULT 0,
            project_share_token TEXT NULL, project_share_mode TEXT NULL, tenant_code TEXT NOT NULL DEFAULT 'default')");
        $db->exec("INSERT INTO nu_projects (project_id, project_code, project_name, project_is_default) VALUES (1,'default','Default',1),(2,'p2','P2',0),(3,'p3','P3',0)");
        $db->exec('CREATE TABLE nu_users (usr_id TEXT PRIMARY KEY, usr_username TEXT, usr_role TEXT, usr_active INTEGER DEFAULT 1)');
        $db->exec("INSERT INTO nu_users (usr_id, usr_username, usr_role) VALUES ('1','root','globeadmin'),('3','carla','clerk'),('4','sam','clerk')");
        $db->exec('CREATE TABLE nu_roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_code TEXT)');
        $db->exec("INSERT INTO nu_roles (role_code) VALUES ('clerk')");
        $db->exec('CREATE TABLE nu_permissions (perm_id INTEGER PRIMARY KEY AUTOINCREMENT, perm_code TEXT)');
        $db->exec('CREATE TABLE nu_role_permissions (rp_id INTEGER PRIMARY KEY AUTOINCREMENT, rp_role_id INTEGER, rp_perm_id INTEGER)');
        $db->exec('CREATE TABLE nu_project_members (pm_id INTEGER PRIMARY KEY AUTOINCREMENT, pm_project_id INTEGER, pm_user_id TEXT, pm_role TEXT)');
        $db->exec("INSERT INTO nu_project_members (pm_project_id, pm_user_id, pm_role) VALUES (2,'3','member')");
        $db->exec('CREATE TABLE nu_project_tables (pt_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, table_name TEXT UNIQUE, form_code TEXT)');
        $db->exec("INSERT INTO nu_project_tables (project_id, table_name, form_code) VALUES (2,'acme_orders','orders'),(3,'other_data','other')");
        $db->exec("CREATE TABLE nu_forms (form_id INTEGER PRIMARY KEY AUTOINCREMENT, form_name TEXT, form_code TEXT, form_table TEXT, form_layout TEXT DEFAULT '[]', form_active INTEGER DEFAULT 1, project_id INTEGER DEFAULT 1)");
        $db->exec("INSERT INTO nu_forms (form_code, form_table, project_id) VALUES ('orders','acme_orders',2),('other','other_data',3)");
        $db->exec('CREATE TABLE nu_role_form_permissions (rfp_id INTEGER PRIMARY KEY AUTOINCREMENT, rfp_role_code TEXT, rfp_form_code TEXT,
            rfp_can_view INTEGER DEFAULT 0, rfp_can_add INTEGER DEFAULT 0, rfp_can_edit INTEGER DEFAULT 0, rfp_can_delete INTEGER DEFAULT 0, rfp_can_export INTEGER DEFAULT 0)');
        $db->exec("INSERT INTO nu_role_form_permissions (rfp_role_code, rfp_form_code, rfp_can_view) VALUES ('clerk','orders',1),('clerk','other',1)");
        $db->exec('CREATE TABLE acme_orders (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec("INSERT INTO acme_orders (name) VALUES ('first')");
        $db->exec('CREATE TABLE other_data (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec("INSERT INTO other_data (name) VALUES ('secret')");
        $db->exec("CREATE TABLE nu_queries (query_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER DEFAULT 1, query_code TEXT, query_name TEXT,
            query_sql TEXT, query_description TEXT, query_parameters TEXT, query_active INTEGER DEFAULT 1)");
        $db->exec("INSERT INTO nu_queries (project_id, query_code, query_name, query_sql) VALUES (2,'mine','Mine','SELECT 1 AS one'),(3,'theirs','Theirs','SELECT 2 AS two')");
    }

    private static function denied(array $r): bool
    {
        return in_array($r['status'], [401, 403], true) || (is_array($r['json']) && ($r['json']['success'] ?? null) === false) || isset($r['json']['error']);
    }

    /** @return array<string, array{string, array, array, bool}> endpoint, params, session, mayAccess */
    public static function matrix(): array
    {
        $anon = [];
        $rows = [
            // endpoint, params, [anonymous, stranger, member, globeadmin] may-access flags
            'lookup own-project table'      => ['api/lookup.php', ['table' => 'acme_orders'], [false, false, true,  true]],
            'lookup other-project table'    => ['api/lookup.php', ['table' => 'other_data'],  [false, false, false, true]],
            'lookup system table'           => ['api/lookup.php', ['table' => 'nu_users'],    [false, false, false, false]],
            'form list own form'            => ['api/form.php', ['action' => 'list', 'code' => 'orders'], [false, false, true, true]],
            'form list other-project form'  => ['api/form.php', ['action' => 'list', 'code' => 'other'],  [false, false, false, true]],
            'form designer load'            => ['api/form.php', ['action' => 'load_form', 'form_id' => 1], [false, false, false, true]],
            'export own table (no flag)'    => ['api/export.php', ['table' => 'acme_orders', 'format' => 'csv'], [false, false, false, true]],
            'export other-project table'    => ['api/export.php', ['table' => 'other_data', 'format' => 'csv'],  [false, false, false, true]],
            'export system table'           => ['api/export.php', ['table' => 'nu_users', 'format' => 'csv'],    [false, false, false, true]],
        ];
        $sessions = ['anonymous' => [], 'stranger' => self::STRANGER, 'member' => self::MEMBER, 'globeadmin' => self::GLOBE];
        $out = [];
        foreach ($rows as $label => [$endpoint, $params, $flags]) {
            $i = 0;
            foreach ($sessions as $who => $session) {
                $out["$label as $who"] = [$endpoint, $params, $session, $flags[$i++]];
            }
        }
        return $out;
    }

    /** @dataProvider matrix */
    public function testEndpointAsRole(string $endpoint, array $params, array $session, bool $mayAccess): void
    {
        $r = EndpointRunner::call($endpoint, $params, $session);
        $this->assertNotSame(0, $r['status'], 'endpoint did not run: ' . substr($r['body'], 0, 200));
        if ($mayAccess) {
            $this->assertFalse(self::denied($r), 'should be allowed, got ' . $r['status'] . ' ' . substr($r['body'], 0, 160));
        } else {
            $this->assertTrue(self::denied($r), 'should be denied, got ' . $r['status'] . ' ' . substr($r['body'], 0, 160));
        }
    }

    public function testQueryRunNeedsThePermissionAndStaysInTheProject(): void
    {
        $db = NuDatabase::getInstance();
        // A member without the 'queries' permission is refused.
        $this->assertTrue(self::denied(EndpointRunner::call('api/query.php', ['code' => 'mine'], self::MEMBER)));
        $db->exec("INSERT INTO nu_permissions (perm_code) VALUES ('queries')");
        $db->exec("INSERT INTO nu_role_permissions (rp_role_id, rp_perm_id) VALUES (1, 1)");
        $own = EndpointRunner::call('api/query.php', ['code' => 'mine'], self::MEMBER);
        $this->assertTrue($own['json']['success'] ?? false, $own['body']);
        $other = EndpointRunner::call('api/query.php', ['code' => 'theirs'], self::MEMBER);
        $this->assertFalse($other['json']['success'] ?? true, $other['body']);
        $this->assertTrue(self::denied(EndpointRunner::call('api/query.php', ['code' => 'mine'], [])));
    }
}
