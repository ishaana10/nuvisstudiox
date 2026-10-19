<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;
use NuAuth;

class AuthBypassTest extends TestCase {

    public function testCheckAuthRejectsEmptySession(): void {
        $_SESSION = [];
        $auth = new NuAuth();
        $this->assertFalse($auth->checkAuth());
    }

    public function testCheckAuthAcceptsValidSession(): void {
        $_SESSION['nu_user_id'] = 1;
        $_SESSION['nu_last_activity'] = time();
        $auth = new NuAuth();
        $this->assertTrue($auth->checkAuth());
    }

    public function testCheckAuthRejectsExpiredSession(): void {
        $_SESSION['nu_user_id'] = 1;
        $_SESSION['nu_last_activity'] = time() - 7200;
        $auth = new NuAuth();
        $this->assertFalse($auth->checkAuth());
    }
}
