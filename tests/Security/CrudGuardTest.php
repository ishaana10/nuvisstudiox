<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;
use NuDatabase;

require_once NU_ROOT . '/core/CrudGuard.php';

/** Generic CRUD must honour the role-by-form permission matrix (nu_role_form_permissions). */
class CrudGuardTest extends TestCase
{
    private NuDatabase $db;

    protected function setUp(): void
    {
        $this->db = NuDatabase::getInstance();
        foreach (['nu_forms', 'nu_role_form_permissions'] as $t) $this->db->exec("DROP TABLE IF EXISTS {$t}");
        $this->db->exec("CREATE TABLE nu_forms (form_id INTEGER PRIMARY KEY AUTOINCREMENT, form_code TEXT, form_table TEXT, project_id INTEGER DEFAULT 1)");
        $this->db->exec("CREATE TABLE nu_role_form_permissions (rfp_id INTEGER PRIMARY KEY AUTOINCREMENT, rfp_role_code TEXT, rfp_form_code TEXT,
            rfp_can_view INTEGER DEFAULT 0, rfp_can_add INTEGER DEFAULT 0, rfp_can_edit INTEGER DEFAULT 0, rfp_can_delete INTEGER DEFAULT 0, rfp_can_export INTEGER DEFAULT 0)");
        $this->db->exec("INSERT INTO nu_forms (form_code, form_table, project_id) VALUES ('orders','acme_orders',2), ('other','acme_orders',3), ('notes','main_notes',1)");
        $this->db->exec("INSERT INTO nu_role_form_permissions (rfp_role_code, rfp_form_code, rfp_can_view, rfp_can_add, rfp_can_edit, rfp_can_delete) VALUES
            ('clerk','orders',1,1,0,0), ('viewer','orders',1,0,0,0), ('clerk','other',1,1,1,1), ('wild','*',1,0,0,0)");
        unset($GLOBALS['nuConfig']['crudAllowUnmappedTables']);
    }

    public function testMethodsMapToActions(): void
    {
        $this->assertSame('view', \NuCrudGuard::actionFor('GET'));
        $this->assertSame('add', \NuCrudGuard::actionFor('POST'));
        $this->assertSame('edit', \NuCrudGuard::actionFor('PUT'));
        $this->assertSame('delete', \NuCrudGuard::actionFor('DELETE'));
    }

    public function testGlobeadminAlwaysAllowed(): void
    {
        $this->assertTrue(\NuCrudGuard::allowed('DELETE', 'acme_orders', 'globeadmin', 2));
        $this->assertTrue(\NuCrudGuard::allowed('GET', 'no_form_table', 'globeadmin', 2));
    }

    public function testRoleOnlyGetsTheActionsItWasGranted(): void
    {
        $this->assertTrue(\NuCrudGuard::allowed('GET', 'acme_orders', 'clerk', 2));
        $this->assertTrue(\NuCrudGuard::allowed('POST', 'acme_orders', 'clerk', 2));
        $this->assertFalse(\NuCrudGuard::allowed('PUT', 'acme_orders', 'clerk', 2));
        $this->assertFalse(\NuCrudGuard::allowed('DELETE', 'acme_orders', 'clerk', 2));
        $this->assertFalse(\NuCrudGuard::allowed('POST', 'acme_orders', 'viewer', 2));
    }

    public function testRoleWithNoRowIsDenied(): void
    {
        $this->assertFalse(\NuCrudGuard::allowed('GET', 'acme_orders', 'stranger', 2));
    }

    public function testFormInAnotherProjectDoesNotGrantAccess(): void
    {
        // clerk can edit through form 'other', but that form belongs to project 3, not the active project 2
        $this->assertFalse(\NuCrudGuard::allowed('PUT', 'acme_orders', 'clerk', 2));
    }

    public function testWildcardRowApplies(): void
    {
        $this->assertTrue(\NuCrudGuard::allowed('GET', 'main_notes', 'wild', 1));
        $this->assertFalse(\NuCrudGuard::allowed('POST', 'main_notes', 'wild', 1));
    }

    public function testTableWithoutFormIsDeniedUnlessConfigured(): void
    {
        $this->assertFalse(\NuCrudGuard::allowed('GET', 'orphan_table', 'clerk', 2));
        $GLOBALS['nuConfig']['crudAllowUnmappedTables'] = true;
        $this->assertTrue(\NuCrudGuard::allowed('GET', 'orphan_table', 'clerk', 2));
        $this->assertFalse(\NuCrudGuard::allowed('DELETE', 'orphan_table', 'clerk', 2), 'unmapped tables are read-only even when allowed');
    }

    public function testTableMatchIsCaseInsensitive(): void
    {
        $this->assertTrue(\NuCrudGuard::allowed('GET', 'ACME_ORDERS', 'viewer', 2));
    }
}
