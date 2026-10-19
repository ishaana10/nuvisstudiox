<?php
declare(strict_types=1);

class TOTP {
    public static function generateSecret(int $length = 16): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    public static function getQRCodeUrl(string $secret, string $label, string $issuer = 'Nuvis'): string {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function verify(string $secret, string $code, int $window = 1): bool {
        $cleanCode = str_replace([' ', '-'], '', trim($code));
        if (strlen($cleanCode) !== 6) return false;
        $time = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (self::generateCode($secret, $time + $i) === $cleanCode) {
                return true;
            }
        }
        return false;
    }

    public static function generateCode(string $secret, int $timeSlice): string {
        $secretKey = self::base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string {
        $b32 = strtoupper(str_replace('=', '', trim($b32)));
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        for ($i = 0; $i < strlen($b32); $i++) {
            $val = strpos($chars, $b32[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        return $output;
    }
}
