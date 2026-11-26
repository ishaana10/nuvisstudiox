<?php
declare(strict_types=1);

/**
 * Tenant boundary. nu_users, nu_roles and nu_projects carry a tenant_code (default 'default').
 * A non-globeadmin user only manages users and roles of their own tenant and only opens projects
 * of their own tenant, even if a stray membership row says otherwise. globeadmin spans tenants.
 */
final class NuTenant
{
    public const DEFAULT = 'default';

    public static function normalize(mixed $v): string
    {
        $s = strtolower(trim((string)$v));
        return preg_match('/^[a-z0-9_-]{1,64}$/', $s) ? $s : self::DEFAULT;
    }

    public static function ofUser(mixed $userId): string
    {
        $id = trim((string)$userId);
        if ($id === '') return self::DEFAULT;
        try {
            $row = NuDatabase::getInstance()->fetchOne('SELECT tenant_code FROM nu_users WHERE usr_id = ?', [$id]);
            return self::normalize($row['tenant_code'] ?? null);
        } catch (\Throwable $e) {
            return self::DEFAULT; // column not created yet (older install before the self-heal)
        }
    }

    public static function ofProject(int $projectId): string
    {
        try {
            $row = NuDatabase::getInstance()->fetchOne('SELECT tenant_code FROM nu_projects WHERE project_id = ?', [$projectId]);
            return self::normalize($row['tenant_code'] ?? null);
        } catch (\Throwable $e) {
            return self::DEFAULT;
        }
    }

    /** Tenant of the signed-in user (session cache, else looked up). */
    public static function current(): string
    {
        if (!empty($_SESSION['nu_tenant'])) return self::normalize($_SESSION['nu_tenant']);
        $t = self::ofUser($_SESSION['nu_user_id'] ?? '');
        $_SESSION['nu_tenant'] = $t;
        return $t;
    }

    public static function canManage(string $actorRole, string $actorTenant, string $targetTenant): bool
    {
        return strtolower($actorRole) === 'globeadmin' || self::normalize($actorTenant) === self::normalize($targetTenant);
    }

    /** May the actor give this role to a user? Only globeadmin hands out globeadmin; roles must be the actor's tenant's or system roles. */
    public static function canAssignRole(string $actorRole, string $actorTenant, string $roleCode): bool
    {
        $isAdmin = strtolower($actorRole) === 'globeadmin';
        if (strtolower($roleCode) === 'globeadmin') return $isAdmin;
        try {
            $row = NuDatabase::getInstance()->fetchOne('SELECT role_is_system, tenant_code FROM nu_roles WHERE role_code = ?', [$roleCode]);
        } catch (\Throwable $e) {
            $row = null;
        }
        if (!$row) return false;
        if ($isAdmin || !empty($row['role_is_system'])) return true;
        return self::normalize($row['tenant_code'] ?? null) === self::normalize($actorTenant);
    }
}
