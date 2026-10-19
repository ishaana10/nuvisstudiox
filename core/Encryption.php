<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\Backend\FIPSCrypto;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use ParagonIE\CipherSweet\EncryptedField;
use ParagonIE\CipherSweet\BlindIndex;

class NuEncryption {
    private static ?CipherSweet $engine = null;

    public static function getEngine(): CipherSweet {
        if (self::$engine === null) {
            global $nuConfig;
            $rawKey = $nuConfig['encryption']['key'] ?? getenv('NU_ENCRYPTION_KEY') ?? null;
            if (!$rawKey) {
                $rawKey = 'base64:' . base64_encode(hash('sha256', 'nuvis_default_encryption_key_salt_2026', true));
            }
            if (str_starts_with($rawKey, 'base64:')) {
                $decoded = base64_decode(substr($rawKey, 7));
                $hexKey  = bin2hex($decoded);
            } else if (strlen($rawKey) === 64 && ctype_xdigit($rawKey)) {
                $hexKey = $rawKey;
            } else {
                $hexKey = bin2hex(hash('sha256', (string)$rawKey, true));
            }

            $provider = new StringProvider($hexKey);
            self::$engine = new CipherSweet($provider, new FIPSCrypto());
        }
        return self::$engine;
    }

    public static function encryptField(string $table, string $column, string $plaintext): string {
        if ($plaintext === '') return '';
        if (self::isEncrypted($plaintext)) return $plaintext;
        try {
            $field = new EncryptedField(self::getEngine(), $table, $column);
            return 'enc:' . $field->encryptValue($plaintext);
        } catch (\Throwable $e) {
            error_log('[NuEncryption Error] Encrypt failed: ' . $e->getMessage());
            return $plaintext;
        }
    }

    public static function decryptField(string $table, string $column, string $ciphertext): string {
        if ($ciphertext === '' || !self::isEncrypted($ciphertext)) return $ciphertext;
        try {
            $rawEnc = substr($ciphertext, 4);
            $field  = new EncryptedField(self::getEngine(), $table, $column);
            return $field->decryptValue($rawEnc);
        } catch (\Throwable $e) {
            error_log('[NuEncryption Error] Decrypt failed: ' . $e->getMessage());
            return $ciphertext;
        }
    }

    public static function getBlindIndex(string $table, string $column, string $value): string {
        try {
            $field = (new EncryptedField(self::getEngine(), $table, $column))
                ->addBlindIndex(new BlindIndex('idx_' . $column, [], 32));
            return $field->getBlindIndex($value, 'idx_' . $column);
        } catch (\Throwable $e) {
            return hash('sha256', $value);
        }
    }

    public static function isEncrypted(string $value): bool {
        return str_starts_with($value, 'enc:');
    }
}
