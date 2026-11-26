<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProjectContext;

class MultiProjectIsolationTest extends TestCase {
    protected function setUp(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        ProjectContext::resetCache(); // earlier tests may have cached another user's project
    }

    public function testProjectContextGetAndSet(): void {
        $_SESSION['nu_role'] = 'globeadmin';
        $_SESSION['nu_project_id'] = 1;

        $id = ProjectContext::getId();
        $this->assertEquals(1, $id);

        $setOk = ProjectContext::setId(1);
        $this->assertTrue($setOk);
    }

    public function testAccessibleProjectsForGlobeAdmin(): void {
        $_SESSION['nu_role'] = 'globeadmin';
        $projects = ProjectContext::getAccessibleProjects();
        $this->assertIsArray($projects);
    }
}
