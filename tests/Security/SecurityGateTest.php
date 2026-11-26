<?php
declare(strict_types=1);

namespace Nuvis\Tests\Security;

use PHPUnit\Framework\TestCase;

require_once NU_ROOT . '/core/SecurityGate.php';

class SecurityGateTest extends TestCase
{
    private function srv(array $o = []): array
    {
        return $o + ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'studiox.example.fj', 'SCRIPT_NAME' => '/api/crud.php'];
    }

    public function testSafeMethodsPassWithoutToken(): void
    {
        $this->assertNull(\NuSecurityGate::check($this->srv(['REQUEST_METHOD' => 'GET']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []));
    }

    public function testSignedInPostWithoutTokenIsRefused(): void
    {
        $r = \NuSecurityGate::check($this->srv(), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []);
        $this->assertSame(403, $r['status']);
    }

    public function testSignedInPutAndDeleteNeedTokenToo(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH'] as $m) {
            $r = \NuSecurityGate::check($this->srv(['REQUEST_METHOD' => $m]), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []);
            $this->assertSame(403, $r['status'], $m);
        }
    }

    public function testValidHeaderTokenPasses(): void
    {
        $this->assertNull(\NuSecurityGate::check($this->srv(['HTTP_X_CSRF_TOKEN' => 'abc']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []));
    }

    public function testValidFormFieldTokenPasses(): void
    {
        $this->assertNull(\NuSecurityGate::check($this->srv(), ['nu_csrf' => 'abc'], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []));
    }

    public function testWrongTokenIsRefused(): void
    {
        $r = \NuSecurityGate::check($this->srv(['HTTP_X_CSRF_TOKEN' => 'nope']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []);
        $this->assertSame(403, $r['status']);
    }

    public function testCrossSiteOriginIsRefusedEvenWithToken(): void
    {
        $r = \NuSecurityGate::check($this->srv(['HTTP_X_CSRF_TOKEN' => 'abc', 'HTTP_ORIGIN' => 'https://evil.example']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []);
        $this->assertSame(403, $r['status']);
    }

    public function testSameOriginPasses(): void
    {
        $this->assertNull(\NuSecurityGate::check($this->srv(['HTTP_X_CSRF_TOKEN' => 'abc', 'HTTP_ORIGIN' => 'https://studiox.example.fj']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []));
    }

    public function testRefererFallbackWhenNoOrigin(): void
    {
        $r = \NuSecurityGate::check($this->srv(['HTTP_X_CSRF_TOKEN' => 'abc', 'HTTP_REFERER' => 'https://evil.example/x']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], []);
        $this->assertSame(403, $r['status']);
    }

    public function testSignedOutRequestsAreNotGated(): void
    {
        // login, public forms and bearer-token API calls carry no session user
        $this->assertNull(\NuSecurityGate::check($this->srv(), [], [], [], []));
    }

    public function testCanBeSwitchedOff(): void
    {
        $this->assertNull(\NuSecurityGate::check($this->srv(), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], ['csrfEnforce' => false]));
    }

    public function testExemptScriptsSkipTheGate(): void
    {
        $r = \NuSecurityGate::check($this->srv(['SCRIPT_NAME' => '/api/webhook_demo_listener.php']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], ['csrfExemptScripts' => ['webhook_demo_listener.php']]);
        $this->assertNull($r);
    }

    public function testAllowedOriginsConfigAcceptsExtraHost(): void
    {
        $this->assertNull(\NuSecurityGate::check($this->srv(['HTTP_X_CSRF_TOKEN' => 'abc', 'HTTP_ORIGIN' => 'https://app.example.fj']), [], [], ['nu_user_id' => 'u1', 'nu_csrf' => 'abc'], ['allowedOrigins' => ['app.example.fj']]));
    }
}
