<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuDatabase;
use ProjectContext;

/** Project isolation: access checks must fail closed and work with UUID (string) user ids. */
class ProjectIsolationHardeningTest extends TestCase {
    private const U1 = 'e3aa59b0-84b9-11f1-90f3-3cecef001126';
    private const U2 = '16899624-be53-49cf-a80f-37e01574caae';
    private NuDatabase $db;

    protected function setUp(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $this->db = NuDatabase::getInstance();
        foreach (['nu_projects', 'nu_project_members', 'nu_project_tables', 'nu_users'] as $t) $this->db->exec("DROP TABLE IF EXISTS {$t}");
        $this->db->exec("CREATE TABLE nu_projects (project_id INTEGER PRIMARY KEY AUTOINCREMENT, project_code TEXT, project_name TEXT,
            project_owner_id TEXT NULL, project_active INTEGER DEFAULT 1, project_is_default INTEGER DEFAULT 0,
            project_is_public_test INTEGER DEFAULT 0, project_share_token TEXT NULL, project_share_mode TEXT NULL, tenant_code TEXT NOT NULL DEFAULT 'default')");
        $this->db->exec("CREATE TABLE nu_project_members (pm_id INTEGER PRIMARY KEY AUTOINCREMENT, pm_project_id INTEGER, pm_user_id TEXT, pm_role TEXT)");
        $this->db->exec("CREATE TABLE nu_project_tables (pt_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, table_name TEXT NOT NULL UNIQUE, form_code TEXT NULL)");
        $this->db->exec("CREATE TABLE nu_users (usr_id TEXT PRIMARY KEY, tenant_code TEXT NOT NULL DEFAULT 'default')");
        $this->db->exec("INSERT INTO nu_users (usr_id) VALUES ('" . self::U1 . "'), ('" . self::U2 . "')");
        $this->db->exec("INSERT INTO nu_projects (project_code, project_name, project_is_default) VALUES ('default','Default',1), ('acme','Acme',0)");
        $this->db->exec("INSERT INTO nu_project_members (pm_project_id, pm_user_id, pm_role) VALUES (2, '" . self::U1 . "', 'member')");
        $this->db->exec("INSERT INTO nu_project_tables (project_id, table_name) VALUES (2, 'acme_orders'), (1, 'main_notes')");
        unset($GLOBALS['nuConfig']['projectDefaultOpenAccess']);
        $_SESSION = ['nu_user_id' => self::U1, 'nu_role' => 'user'];
        ProjectContext::resetCache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['nuConfig']['projectDefaultOpenAccess']);
        ProjectContext::resetCache();
    }

    public function testMembershipWorksForUuidUserIds(): void {
        $this->assertTrue(ProjectContext::validateAccess(self::U1, 2, 'user'));
        $this->assertFalse(ProjectContext::validateAccess(self::U2, 2, 'user'));
    }

    public function testDefaultProjectIsMembersOnlyUnlessOpenedInConfig(): void {
        $this->assertFalse(ProjectContext::validateAccess(self::U2, 1, 'user'), 'default is members-only');
        $this->assertTrue(ProjectContext::validateAccess(self::U2, 1, 'globeadmin'));
        $GLOBALS['nuConfig']['projectDefaultOpenAccess'] = true;
        $this->assertTrue(ProjectContext::validateAccess(self::U2, 1, 'user'), 'opt-in restores the old open default');
    }

    public function testAccessibleProjectsWorkForUuidMembersAndHideDefaultFromStrangers(): void {
        $codes = fn($rows) => array_column($rows, 'project_code');
        $this->assertSame(['acme'], $codes(ProjectContext::getAccessibleProjects(self::U1, 'user')));
        $this->assertSame([], ProjectContext::getAccessibleProjects(self::U2, 'user'), 'members-only default, not a member of acme');
        $this->assertSame([], ProjectContext::getAccessibleProjects('', 'user'), 'no user: nothing');
    }

    public function testSetIdFailsClosedWhenProjectLookupThrows(): void {
        $this->db->exec('DROP TABLE nu_projects');
        $this->assertFalse(ProjectContext::setId(2));
    }

    public function testSetIdDeniesNonMemberUuidUser(): void {
        $_SESSION['nu_user_id'] = self::U2;
        $this->assertFalse(ProjectContext::setId(2));
        $_SESSION['nu_user_id'] = self::U1;
        $this->assertTrue(ProjectContext::setId(2));
    }

    public function testTableOwnership(): void {
        $this->assertSame(2, ProjectContext::tableProjectId('acme_orders'));
        $this->assertNull(ProjectContext::tableProjectId('unregistered'));
        $this->assertFalse(ProjectContext::canAccessTable('acme_orders', 1, 'user'), 'other project\'s table');
        $this->assertFalse(ProjectContext::canAccessTable('acme_orders', 1, 'globeadmin'), 'even for admin: switch project first');
        $this->assertTrue(ProjectContext::canAccessTable('acme_orders', 2, 'user'));
        $this->assertTrue(ProjectContext::canAccessTable('main_notes', 1, 'user'));
    }

    public function testUnregisteredTablesBelongToTheDefaultProject(): void {
        $this->assertTrue(ProjectContext::canAccessTable('legacy_table', 1, 'user'));
        $this->assertFalse(ProjectContext::canAccessTable('legacy_table', 2, 'user'));
        $this->assertTrue(ProjectContext::canAccessTable('legacy_table', 2, 'globeadmin'));
    }

    public function testRowProjectAccessTreatsMissingProjectAsDefault(): void {
        $this->assertTrue(ProjectContext::canAccessRow(2, 2, 'user'));
        $this->assertFalse(ProjectContext::canAccessRow(2, 1, 'user'));
        $this->assertTrue(ProjectContext::canAccessRow(null, 1, 'user'));
        $this->assertFalse(ProjectContext::canAccessRow(0, 2, 'user'));
        $this->assertTrue(ProjectContext::canAccessRow(null, 2, 'globeadmin'));
    }

    public function testFilesAreScopedToTheirProject(): void {
        require_once dirname(__DIR__, 2) . '/core/FileStore.php';
        $_SESSION['nu_project_id'] = 2; $_SESSION['nu_user_id'] = self::U1; ProjectContext::resetCache();
        $this->assertTrue(\NuFileStore::canAccess(['project_id' => 2]));
        $this->assertFalse(\NuFileStore::canAccess(['project_id' => 1]));
        $this->assertFalse(\NuFileStore::canAccess(['project_id' => null]), 'legacy files belong to the default project');
    }
}
