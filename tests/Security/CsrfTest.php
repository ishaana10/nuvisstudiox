<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;
use NuAuth;

class CsrfTest extends TestCase {

    public function testCsrfTokenGenerationAndVerification(): void {
        $_SESSION = [];
        $auth = new NuAuth();

        $token = $auth->getCsrfToken();
        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token));

        $this->assertTrue($auth->verifyCsrf($token));
        $this->assertFalse($auth->verifyCsrf('invalid_token_123'));
    }
}
