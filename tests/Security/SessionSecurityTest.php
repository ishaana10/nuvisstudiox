<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;

class SessionSecurityTest extends TestCase {

    public function testSessionBootstrapSettings(): void {
        require_once __DIR__ . '/../../core/SessionBootstrap.php';

        $this->assertEquals('nuvisstudioxssid', session_name());
        $this->assertEquals('1', ini_get('session.use_strict_mode'));
        $this->assertEquals('1', ini_get('session.use_only_cookies'));
        $this->assertEquals('1', ini_get('session.cookie_httponly'));
    }
}
