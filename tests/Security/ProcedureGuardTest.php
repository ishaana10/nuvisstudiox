<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;

require_once NU_ROOT . '/core/ProcedureGuard.php';

class ProcedureGuardTest extends TestCase
{
    public function testOnlyGlobeadminMayRunOrEditProcedures(): void
    {
        $this->assertNull(\NuProcedureGuard::check('globeadmin', [], 'development'));
        $this->assertNotNull(\NuProcedureGuard::check('admin', [], 'development'));
        $this->assertNotNull(\NuProcedureGuard::check('user', [], 'development'));
        $this->assertNotNull(\NuProcedureGuard::check('', [], 'development'));
    }

    public function testDisabledOnProductionByDefault(): void
    {
        $this->assertNotNull(\NuProcedureGuard::check('globeadmin', [], 'production'));
    }

    public function testCanBeEnabledExplicitlyOnProduction(): void
    {
        $this->assertNull(\NuProcedureGuard::check('globeadmin', ['enableProcedures' => true], 'production'));
    }

    public function testCanBeDisabledExplicitlyAnywhere(): void
    {
        $this->assertNotNull(\NuProcedureGuard::check('globeadmin', ['enableProcedures' => false], 'development'));
    }

    public function testRoleCheckIsCaseInsensitive(): void
    {
        $this->assertNull(\NuProcedureGuard::check('GlobeAdmin', [], 'staging'));
    }
}
