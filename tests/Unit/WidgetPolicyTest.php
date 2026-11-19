<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuWidgetPolicy;

class WidgetPolicyTest extends TestCase {
    protected function setUp(): void {
        require_once dirname(__DIR__, 2) . '/core/WidgetPolicy.php';
    }

    public function testAdminCannotEditRoleRows(): void {
        foreach (['role_add', 'role_update', 'role_remove', 'role_reorder'] as $a) {
            $this->assertFalse(NuWidgetPolicy::can($a, 'admin'), $a);
        }
    }

    public function testUserCannotRunSql(): void {
        foreach (['run_sql', 'list_tables', 'designer_load', 'types'] as $a) {
            $this->assertFalse(NuWidgetPolicy::can($a, 'user'), $a);
            $this->assertFalse(NuWidgetPolicy::can($a, 'admin'), $a);
        }
    }

    public function testGlobeadminCanDoEverything(): void {
        foreach (['run_sql', 'list_tables', 'designer_load', 'types', 'role_add', 'role_update', 'role_remove', 'role_reorder', 'render', 'prefs_save'] as $a) {
            $this->assertTrue(NuWidgetPolicy::can($a, 'globeadmin'), $a);
        }
    }

    public function testAnyAuthenticatedRoleMayUseSelfServiceActions(): void {
        foreach (['list', 'render', 'prefs_save', 'prefs_reset', 'settings_save'] as $a) {
            $this->assertTrue(NuWidgetPolicy::can($a, 'user'), $a);
            $this->assertTrue(NuWidgetPolicy::can($a, 'manager'), $a);
        }
    }

    public function testEmptyRoleDeniedAll(): void {
        $this->assertFalse(NuWidgetPolicy::can('list', ''));
        $this->assertFalse(NuWidgetPolicy::can('render', ''));
    }

    public function testUnknownActionDenied(): void {
        $this->assertFalse(NuWidgetPolicy::can('drop_everything', 'globeadmin'));
    }

    public function testRoleMatchIsCaseInsensitive(): void {
        $this->assertTrue(NuWidgetPolicy::can('run_sql', 'GlobeAdmin'));
    }
}
