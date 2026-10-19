<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TOTP;
use Nuvis\Sso\SsoManager;

class MfaAndSsoTest extends TestCase {

    public function testTOTPGenerationAndVerification(): void {
        require_once __DIR__ . '/../../lib/TOTP.php';

        $secret = TOTP::generateSecret();
        $this->assertEquals(16, strlen($secret));

        $time = (int)floor(time() / 30);
        $code = TOTP::generateCode($secret, $time);
        $this->assertEquals(6, strlen($code));

        $this->assertTrue(TOTP::verify($secret, $code));
        $this->assertFalse(TOTP::verify($secret, '000000'));
    }

    public function testTOTPQRCodeUrl(): void {
        require_once __DIR__ . '/../../lib/TOTP.php';

        $secret = 'JBSWY3DPEHPK3PXP';
        $url = TOTP::getQRCodeUrl($secret, 'testuser@example.com', 'NuvisTest');
        $this->assertStringContainsString('otpauth://totp/', $url);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $url);
        $this->assertStringContainsString('NuvisTest', $url);
    }

    public function testSsoManagerActiveProviders(): void {
        $providers = SsoManager::getActiveProviders();
        $this->assertIsArray($providers);
    }
}
