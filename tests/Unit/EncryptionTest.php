<?php
declare(strict_types=1);

namespace Nuvis\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NuEncryption;

class EncryptionTest extends TestCase {

    public function testFieldEncryptionAndDecryption(): void {
        require_once __DIR__ . '/../../core/Encryption.php';

        $plaintext = 'SecretTOTPKey12345';
        $encrypted = NuEncryption::encryptField('nu_users', 'usr_2fa_secret', $plaintext);

        $this->assertTrue(NuEncryption::isEncrypted($encrypted));
        $this->assertStringStartsWith('enc:', $encrypted);
        $this->assertNotEquals($plaintext, $encrypted);

        $decrypted = NuEncryption::decryptField('nu_users', 'usr_2fa_secret', $encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function testBlindIndexGeneration(): void {
        require_once __DIR__ . '/../../core/Encryption.php';

        $index1 = NuEncryption::getBlindIndex('nu_users', 'usr_email', 'admin@example.com');
        $index2 = NuEncryption::getBlindIndex('nu_users', 'usr_email', 'admin@example.com');

        $this->assertNotEmpty($index1);
        $this->assertEquals($index1, $index2);
    }
}
