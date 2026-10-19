<?php
declare(strict_types=1);

namespace Nuvis\Sso;

use NuDatabase;

require_once __DIR__ . '/SsoProviderInterface.php';
require_once __DIR__ . '/SamlProvider.php';
require_once __DIR__ . '/OidcProvider.php';

class SsoManager {
    public static function getActiveProviders(): array {
        try {
            $db = NuDatabase::getInstance();
            return $db->fetchAll("SELECT * FROM nu_sso_providers WHERE enabled = 1 ORDER BY is_default DESC, name ASC") ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getProvider($providerId): ?SsoProviderInterface {
        try {
            $db = NuDatabase::getInstance();
            if ($providerId === 'default') {
                $row = $db->fetchOne("SELECT * FROM nu_sso_providers WHERE enabled = 1 ORDER BY is_default DESC LIMIT 1");
            } else {
                $row = $db->fetchOne("SELECT * FROM nu_sso_providers WHERE id = :id AND enabled = 1", [':id' => $providerId]);
            }
            if (!$row) return null;

            if ($row['type'] === 'saml') {
                return new SamlProvider($row);
            } elseif ($row['type'] === 'oidc') {
                return new OidcProvider($row);
            }
        } catch (\Throwable $e) {}
        return null;
    }

    public static function findOrCreateFromSso(array $attributes): ?array {
        try {
            $db = NuDatabase::getInstance();
            $email    = trim((string)($attributes['email'] ?? ''));
            $username = trim((string)($attributes['username'] ?? $email));
            $name     = trim((string)($attributes['name'] ?? $username));

            if ($email === '' && $username === '') return null;

            // Match existing user by email or username
            $user = null;
            if ($email !== '') {
                $user = $db->fetchOne("SELECT * FROM nu_users WHERE usr_email = :e LIMIT 1", [':e' => $email]);
            }
            if (!$user && $username !== '') {
                $user = $db->fetchOne("SELECT * FROM nu_users WHERE usr_username = :u LIMIT 1", [':u' => $username]);
            }

            if ($user) {
                // Update email or name if missing
                $update = [];
                if (empty($user['usr_email']) && $email !== '') $update['usr_email'] = $email;
                if (!empty($update)) {
                    $db->update('nu_users', $update, "usr_id = :id", [':id' => $user['usr_id']]);
                    $user = array_merge($user, $update);
                }
                return $user;
            }

            // JIT Provisioning (Just-In-Time)
            $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $role = $attributes['role'] ?? 'user';

            $newId = $db->insert('nu_users', [
                'usr_username'             => $username ?: $email,
                'usr_email'                => $email,
                'usr_password'             => $randomPassword,
                'usr_role'                 => $role,
                'usr_active'               => 1,
                'usr_must_change_password' => 0,
            ]);

            return $db->fetchOne("SELECT * FROM nu_users WHERE usr_id = :id", [':id' => $newId]);
        } catch (\Throwable $e) {
            error_log('[SSO JIT Error] ' . $e->getMessage());
            return null;
        }
    }
}
