<?php
declare(strict_types=1);

/**
 * Procedures are PHP stored in the database and run with eval(), so they are code, not data.
 * Only globeadmin may run or edit them, and they are switched off on production unless
 * $nuConfig['enableProcedures'] = true (set explicitly in config.local.php).
 */
final class NuProcedureGuard
{
    /** @return string|null error message, or null when allowed */
    public static function check(string $role, array $config, ?string $env = null): ?string
    {
        $env = $env ?? (getenv('NU_ENV') ?: 'development');
        $enabled = array_key_exists('enableProcedures', $config) ? (bool)$config['enableProcedures'] : ($env !== 'production');
        if (!$enabled) {
            return 'Procedures are disabled on this server. Set $nuConfig[\'enableProcedures\'] = true in config.local.php to allow them.';
        }
        if (strtolower($role) !== 'globeadmin') {
            return 'Permission denied. Only globeadmin may run or edit procedures.';
        }
        return null;
    }

    /** Sends the 403 JSON and stops when the check fails. */
    public static function enforce(string $role): void
    {
        global $nuConfig;
        $err = self::check($role, is_array($nuConfig ?? null) ? $nuConfig : []);
        if ($err !== null) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $err]);
            exit;
        }
    }
}
