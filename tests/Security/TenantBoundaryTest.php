<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use ProjectContext;

require_once NU_ROOT . '/core/Tenant.php';

/** Users, roles and projects carry a tenant code; non-globeadmin users never cross it. */
class TenantBoundaryTest extends TestCase
{
    private const A = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const B = 'bbbbbbbb-0000-4000-8000-000000000002';
    private NuDatabase $db;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $this->db = NuDatabase::getInstance();
        foreach (['nu_users', 'nu_roles', 'nu_projects', 'nu_project_members', 'nu_project_tables'] as $t) $this->db->exec("DROP TABLE IF EXISTS {$t}");
        $this->db->exec("CREATE TABLE nu_users (usr_id TEXT PRIMARY KEY, usr_username TEXT, usr_role TEXT, tenant_code TEXT NOT NULL DEFAULT 'default')");
        $this->db->exec("CREATE TABLE nu_roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_code TEXT, role_is_system INTEGER DEFAULT 0, tenant_code TEXT NOT NULL DEFAULT 'default')");
        $this->db->exec("CREATE TABLE nu_projects (project_id INTEGER PRIMARY KEY AUTOINCREMENT, project_code TEXT, project_name TEXT, project_owner_id TEXT NULL,
            project_active INTEGER DEFAULT 1, project_is_default INTEGER DEFAULT 0, project_is_public_test INTEGER DEFAULT 0, project_share_token TEXT NULL,
            project_share_mode TEXT NULL, tenant_code TEXT NOT NULL DEFAULT 'default')");
        $this->db->exec("CREATE TABLE nu_project_members (pm_id INTEGER PRIMARY KEY AUTOINCREMENT, pm_project_id INTEGER, pm_user_id TEXT, pm_role TEXT)");
        $this->db->exec("CREATE TABLE nu_project_tables (pt_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, table_name TEXT NOT NULL)");
        $this->db->exec("INSERT INTO nu_users VALUES ('" . self::A . "','ann','user','acme'), ('" . self::B . "','bob','user','beta')");
        $this->db->exec("INSERT INTO nu_roles (role_code, role_is_system, tenant_code) VALUES ('user',1,'default'), ('globeadmin',1,'default'), ('acme_clerk',0,'acme'), ('beta_clerk',0,'beta')");
        $this->db->exec("INSERT INTO nu_projects (project_code, project_name, tenant_code) VALUES ('acme-app','Acme App','acme')");
        // bob is (wrongly) a member of acme's project; the tenant check must still keep him out
        $this->db->exec("INSERT INTO nu_project_members (pm_project_id, pm_user_id, pm_role) VALUES (1, '" . self::A . "', 'member'), (1, '" . self::B . "', 'member')");
        $_SESSION = [];
        ProjectContext::resetCache();
    }

    public function testNormalizeFallsBackToDefault(): void
    {
        $this->assertSame('acme', \NuTenant::normalize('Acme'));
        $this->assertSame('default', \NuTenant::normalize(''));
        $this->assertSame('default', \NuTenant::normalize("x'; DROP"));
        $this->assertSame('default', \NuTenant::normalize(null));
    }

    public function testTenantOfUser(): void
    {
        $this->assertSame('acme', \NuTenant::ofUser(self::A));
        $this->assertSame('default', \NuTenant::ofUser('missing'));
    }

    public function testManageRules(): void
    {
        $this->assertTrue(\NuTenant::canManage('globeadmin', 'default', 'acme'));
        $this->assertTrue(\NuTenant::canManage('admin', 'acme', 'acme'));
        $this->assertFalse(\NuTenant::canManage('admin', 'acme', 'beta'));
    }

    public function testRoleAssignment(): void
    {
        $this->assertFalse(\NuTenant::canAssignRole('admin', 'acme', 'globeadmin'), 'no privilege escalation');
        $this->assertTrue(\NuTenant::canAssignRole('globeadmin', 'default', 'globeadmin'));
        $this->assertTrue(\NuTenant::canAssignRole('admin', 'acme', 'acme_clerk'));
        $this->assertFalse(\NuTenant::canAssignRole('admin', 'acme', 'beta_clerk'), 'other tenant role');
        $this->assertTrue(\NuTenant::canAssignRole('admin', 'acme', 'user'), 'system roles are shared');
        $this->assertFalse(\NuTenant::canAssignRole('admin', 'acme', 'nonexistent'));
    }

    public function testProjectAccessStopsAtTheTenantBoundary(): void
    {
        $this->assertTrue(ProjectContext::validateAccess(self::A, 1, 'user'));
        $this->assertFalse(ProjectContext::validateAccess(self::B, 1, 'user'), 'member row alone is not enough across tenants');
        $this->assertTrue(ProjectContext::validateAccess(self::B, 1, 'globeadmin'));
    }

    public function testAccessibleProjectsExcludeOtherTenants(): void
    {
        $this->assertSame(['acme-app'], array_column(ProjectContext::getAccessibleProjects(self::A, 'user'), 'project_code'));
        $this->assertSame([], ProjectContext::getAccessibleProjects(self::B, 'user'));
    }
}
