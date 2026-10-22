<?php
declare(strict_types=1);

/**
 * ProjectContext Helper Class
 * Manages active project context, permissions, and database table registrations.
 */
class ProjectContext {
    private static ?int $cachedProjectId = null;

    /**
     * Resets the in-memory static cache for project context.
     */
    public static function resetCache(): void {
        self::$cachedProjectId = null;
    }

    /**
     * Gets the current active project ID from session or resolves default/assigned project for the user.
     *
     * @return int
     */
    public static function getId(): int {
        if (self::$cachedProjectId !== null) {
            return self::$cachedProjectId;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db = NuDatabase::getInstance();
        $sessUser = $_SESSION['user'] ?? null;
        $sessUserId = is_array($sessUser) ? ($sessUser['usr_id'] ?? null) : (is_object($sessUser) ? ($sessUser->usr_id ?? null) : null);
        $sessUserRole = is_array($sessUser) ? ($sessUser['usr_role'] ?? null) : (is_object($sessUser) ? ($sessUser->usr_role ?? null) : null);

        $userId = $_SESSION['nu_user_id'] ?? ($sessUserId ?? ($_SESSION['usr_id'] ?? ($_SESSION['user_id'] ?? null)));
        $userRole = $_SESSION['nu_role'] ?? ($sessUserRole ?? ($_SESSION['usr_role'] ?? ($_SESSION['user_role'] ?? '')));

        if (($userId === null || $userRole === '') && class_exists('NuAuth')) {
            $u = NuAuth::getInstance()->getCurrentUser();
            if (is_array($u)) {
                $userId = $userId ?? ($u['usr_id'] ?? ($u['id'] ?? null));
                $userRole = $userRole !== '' ? $userRole : ($u['usr_role'] ?? ($u['role'] ?? ''));
            } elseif (is_object($u)) {
                $userId = $userId ?? ($u->usr_id ?? ($u->id ?? null));
                $userRole = $userRole !== '' ? $userRole : ($u->usr_role ?? ($u->role ?? ''));
            }
        }

        // 0. Check URL or session for project_share token
        $shareToken = $_GET['project_share'] ?? ($_GET['share_token'] ?? ($_SESSION['project_share_token'] ?? ''));
        if (!empty($shareToken) && is_string($shareToken)) {
            try {
                $proj = $db->fetchOne(
                    "SELECT * FROM nu_projects WHERE project_share_token = ? AND project_is_public_test = 1 AND project_active = 1",
                    [$shareToken]
                );
                if ($proj) {
                    $pid = (int)$proj['project_id'];
                    $_SESSION['nu_project_id']           = $pid;
                    $_SESSION['nu_project_code']         = $proj['project_code'];
                    $_SESSION['project_share_token']     = $shareToken;
                    $_SESSION['nu_project_share_active']  = true;
                    $_SESSION['nu_project_share_mode']    = $proj['project_share_mode'] ?? 'guest';

                    // Auto-bootstrap guest test session if user is not logged in and mode is 'guest'
                    if (empty($_SESSION['nu_user_id']) && ($proj['project_share_mode'] ?? 'guest') === 'guest') {
                        $_SESSION['nu_user_id']       = 'guest_tester';
                        $_SESSION['nu_username']      = 'Guest Tester';
                        $_SESSION['nu_role']          = 'user';
                        $_SESSION['nu_last_activity'] = time();
                        if (empty($_SESSION['nu_csrf'])) {
                            $_SESSION['nu_csrf'] = bin2hex(random_bytes(32));
                        }
                    }

                    return self::$cachedProjectId = $pid;
                }
            } catch (\Throwable $e) {}
        }

        // Check if session project ID exists
        try {
            if (!empty($_SESSION['nu_project_id'])) {
                $sessPid = (int)$_SESSION['nu_project_id'];

                // Verify project exists & active
                $proj = $db->fetchOne("SELECT project_id, project_code FROM nu_projects WHERE project_id = ? AND project_active = 1", [$sessPid]);
                if ($proj) {
                    // If user is globeadmin, allow access immediately
                    if ($userRole === 'globeadmin' || empty($userId)) {
                        $_SESSION['nu_project_code'] = $proj['project_code'];
                        return self::$cachedProjectId = $sessPid;
                    }

                    // For non-globeadmin users, verify member assignment
                    if (self::validateAccess((int)$userId, $sessPid, $userRole)) {
                        $_SESSION['nu_project_code'] = $proj['project_code'];
                        return self::$cachedProjectId = $sessPid;
                    }
                }
            }

            // Resolve default or assigned project for user
            $resolvedId = self::resolveDefaultProjectForUser($userId ? (int)$userId : null, $userRole);
            $_SESSION['nu_project_id'] = $resolvedId;

            $projRow = $db->fetchOne("SELECT project_code FROM nu_projects WHERE project_id = ?", [$resolvedId]);
            if ($projRow) {
                $_SESSION['nu_project_code'] = $projRow['project_code'];
            }

            return self::$cachedProjectId = $resolvedId;
        } catch (\Throwable $e) {
            return self::$cachedProjectId = 1;
        }
    }

    /**
     * Sets the current active project ID in the session after validating permissions.
     *
     * @param int $projectId
     * @return bool
     */
    public static function setId(int $projectId): bool {
        self::$cachedProjectId = null;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db = NuDatabase::getInstance();
        try {
            $proj = $db->fetchOne("SELECT project_id, project_code FROM nu_projects WHERE project_id = ? AND project_active = 1", [$projectId]);
            if (!$proj) {
                return false;
            }
        } catch (\Throwable $e) {
            return true;
        }

        $sessUser = $_SESSION['user'] ?? null;
        $sessUserId = is_array($sessUser) ? ($sessUser['usr_id'] ?? null) : (is_object($sessUser) ? ($sessUser->usr_id ?? null) : null);
        $sessUserRole = is_array($sessUser) ? ($sessUser['usr_role'] ?? null) : (is_object($sessUser) ? ($sessUser->usr_role ?? null) : null);

        $userId = $_SESSION['nu_user_id'] ?? ($sessUserId ?? ($_SESSION['usr_id'] ?? ($_SESSION['user_id'] ?? null)));
        $userRole = $_SESSION['nu_role'] ?? ($sessUserRole ?? ($_SESSION['usr_role'] ?? ($_SESSION['user_role'] ?? '')));

        if (($userId === null || $userRole === '') && class_exists('NuAuth')) {
            $u = NuAuth::getInstance()->getCurrentUser();
            if (is_array($u)) {
                $userId = $userId ?? ($u['usr_id'] ?? ($u['id'] ?? null));
                $userRole = $userRole !== '' ? $userRole : ($u['usr_role'] ?? ($u['role'] ?? ''));
            } elseif (is_object($u)) {
                $userId = $userId ?? ($u->usr_id ?? ($u->id ?? null));
                $userRole = $userRole !== '' ? $userRole : ($u->usr_role ?? ($u->role ?? ''));
            }
        }

        if (!empty($userId) && $userRole !== 'globeadmin') {
            if (!self::validateAccess((int)$userId, $projectId, $userRole)) {
                return false;
            }
        }

        $_SESSION['nu_project_id']   = (int)$proj['project_id'];
        $_SESSION['nu_project_code'] = $proj['project_code'];

        // Optionally persist last project ID in nu_user_meta if user is logged in
        if (!empty($userId) && class_exists('NuAuth')) {
            try {
                $driver = $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
                $hasMetaTable = false;
                if ($driver === 'sqlite') {
                    $hasMetaTable = (bool)$db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='nu_user_meta'");
                } else {
                    $hasMetaTable = (bool)$db->fetchOne("SHOW TABLES LIKE 'nu_user_meta'");
                }
                if ($hasMetaTable) {
                    $existing = $db->fetchOne("SELECT umeta_id FROM nu_user_meta WHERE umeta_user_id = ? AND umeta_key = 'last_project_id'", [$userId]);
                    if ($existing) {
                        $db->update('nu_user_meta', ['umeta_value' => (string)$projectId], 'umeta_id = ?', [$existing['umeta_id']]);
                    } else {
                        $db->insert('nu_user_meta', [
                            'umeta_user_id' => $userId,
                            'umeta_key'     => 'last_project_id',
                            'umeta_value'   => (string)$projectId
                        ]);
                    }
                }
            } catch (Exception $e) {}
        }

        return true;
    }

    /**
     * Resolves default or first accessible project for a given user.
     *
     * @param int|null $userId
     * @param string $userRole
     * @return int
     */
    private static function resolveDefaultProjectForUser(?int $userId, string $userRole): int {
        $db = NuDatabase::getInstance();

        // 1. If globeadmin or unauthenticated, return system default project
        if ($userRole === 'globeadmin' || empty($userId)) {
            try {
                $defaultProj = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_is_default = 1 AND project_active = 1 LIMIT 1");
                if ($defaultProj) {
                    return (int)$defaultProj['project_id'];
                }
                $anyProj = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_active = 1 ORDER BY project_id ASC LIMIT 1");
                return $anyProj ? (int)$anyProj['project_id'] : 1;
            } catch (\Throwable $e) {
                return 1;
            }
        }

        // 2. Check user meta for last_project_id
        try {
            $metaRow = $db->fetchOne("SELECT umeta_value FROM nu_user_meta WHERE umeta_user_id = ? AND umeta_key = 'last_project_id'", [$userId]);
            if (!empty($metaRow['umeta_value'])) {
                $lastPid = (int)$metaRow['umeta_value'];
                if (self::validateAccess($userId, $lastPid, $userRole)) {
                    $proj = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_id = ? AND project_active = 1", [$lastPid]);
                    if ($proj) {
                        return $lastPid;
                    }
                }
            }
        } catch (Exception $e) {}

        // 3. For standard user, fetch first assigned project from nu_project_members
        $assigned = $db->fetchOne(
            "SELECT p.project_id FROM nu_projects p
             INNER JOIN nu_project_members pm ON p.project_id = pm.pm_project_id
             WHERE pm.pm_user_id = ? AND p.project_active = 1
             ORDER BY p.project_is_default DESC, p.project_id ASC LIMIT 1",
            [$userId]
        );

        if ($assigned) {
            return (int)$assigned['project_id'];
        }

        // 4. Fallback to default project if user is not specifically assigned anywhere
        $defaultProj = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_is_default = 1 AND project_active = 1 LIMIT 1");
        return $defaultProj ? (int)$defaultProj['project_id'] : 1;
    }

    /**
     * Checks if a user has access to a specific project.
     *
     * @param int $userId
     * @param int $projectId
     * @param string $userRole
     * @return bool
     */
    public static function validateAccess(int $userId, int $projectId, string $userRole = ''): bool {
        if ($userRole === 'globeadmin') {
            return true;
        }

        $db = NuDatabase::getInstance();

        // Allow access if user is owner of project
        $ownerProj = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_id = ? AND project_owner_id = ? AND project_active = 1", [$projectId, $userId]);
        if ($ownerProj) {
            return true;
        }

        $row = $db->fetchOne(
            "SELECT pm_id FROM nu_project_members WHERE pm_project_id = ? AND pm_user_id = ?",
            [$projectId, $userId]
        );

        if (!empty($row)) {
            return true;
        }

        // Allow access if active share session for this project exists
        if (!empty($_SESSION['nu_project_share_active']) && (int)($_SESSION['nu_project_id'] ?? 0) === $projectId) {
            return true;
        }

        // Allow access to default project if user is not explicitly restricted
        $defaultProj = $db->fetchOne("SELECT project_id FROM nu_projects WHERE project_id = ? AND project_is_default = 1 AND project_active = 1", [$projectId]);
        return !empty($defaultProj);
    }

    /**
     * Returns all accessible projects for a user.
     *
     * @param int|null $userId
     * @param string $userRole
     * @return array
     */
    public static function getAccessibleProjects(?int $userId = null, string $userRole = ''): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessUser = $_SESSION['user'] ?? null;
        $sessUserId = is_array($sessUser) ? ($sessUser['usr_id'] ?? null) : (is_object($sessUser) ? ($sessUser->usr_id ?? null) : null);
        $sessUserRole = is_array($sessUser) ? ($sessUser['usr_role'] ?? null) : (is_object($sessUser) ? ($sessUser->usr_role ?? null) : null);

        if ($userId === null) {
            $userId = $_SESSION['nu_user_id'] ?? ($sessUserId ?? ($_SESSION['usr_id'] ?? ($_SESSION['user_id'] ?? null)));
        }
        if (empty($userRole)) {
            $userRole = $_SESSION['nu_role'] ?? ($sessUserRole ?? ($_SESSION['usr_role'] ?? ($_SESSION['user_role'] ?? '')));
        }

        if (($userId === null || empty($userRole)) && class_exists('NuAuth')) {
            $u = NuAuth::getInstance()->getCurrentUser();
            if (is_array($u)) {
                $userId = $userId ?? ($u['usr_id'] ?? ($u['id'] ?? null));
                $userRole = !empty($userRole) ? $userRole : ($u['usr_role'] ?? ($u['role'] ?? ''));
            } elseif (is_object($u)) {
                $userId = $userId ?? ($u->usr_id ?? ($u->id ?? null));
                $userRole = !empty($userRole) ? $userRole : ($u->usr_role ?? ($u->role ?? ''));
            }
        }

        $db = NuDatabase::getInstance();

        try {
            if ($userRole === 'globeadmin') {
                return $db->fetchAll("SELECT * FROM nu_projects WHERE project_active = 1 ORDER BY project_is_default DESC, project_name ASC");
            }

            if (empty($userId)) {
                return $db->fetchAll("SELECT * FROM nu_projects WHERE project_is_default = 1 AND project_active = 1");
            }

            $projects = $db->fetchAll(
                "SELECT p.*, COALESCE(pm.pm_role, CASE WHEN p.project_owner_id = ? THEN 'owner' ELSE 'member' END) AS pm_role FROM nu_projects p
                 LEFT JOIN nu_project_members pm ON p.project_id = pm.pm_project_id AND pm.pm_user_id = ?
                 WHERE (pm.pm_user_id = ? OR p.project_owner_id = ? OR p.project_is_default = 1) AND p.project_active = 1
                 ORDER BY p.project_is_default DESC, p.project_name ASC",
                [$userId, $userId, $userId, $userId]
            );

            if (empty($projects)) {
                $projects = $db->fetchAll("SELECT *, 'member' AS pm_role FROM nu_projects WHERE project_is_default = 1 AND project_active = 1");
            }

            return $projects;
        } catch (\Throwable $e) {
            return [['project_id' => 1, 'project_code' => 'default', 'project_name' => 'Default Project', 'project_active' => 1, 'project_is_default' => 1]];
        }
    }

    /**
     * Registers a custom form table in nu_project_tables registry.
     *
     * @param int $projectId
     * @param string $tableName
     * @param string|null $formCode
     * @return bool
     */
    public static function registerProjectTable(int $projectId, string $tableName, ?string $formCode = null): bool {
        $tableName = trim($tableName);
        if ($tableName === '') {
            return false;
        }

        $db = NuDatabase::getInstance();
        $driver = $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            $existing = $db->fetchOne("SELECT pt_id, project_id FROM nu_project_tables WHERE table_name = ?", [$tableName]);
            if ($existing) {
                if ((int)$existing['project_id'] !== $projectId || $formCode !== null) {
                    $db->update('nu_project_tables', [
                        'project_id' => $projectId,
                        'form_code'  => $formCode
                    ], 'pt_id = ?', [$existing['pt_id']]);
                }
                return true;
            }

            if ($driver === 'sqlite') {
                $db->exec("INSERT OR IGNORE INTO nu_project_tables (project_id, table_name, form_code) VALUES ({$projectId}, " . $db->getPdo()->quote($tableName) . ", " . ($formCode ? $db->getPdo()->quote($formCode) : 'NULL') . ")");
            } else {
                $db->insert('nu_project_tables', [
                    'project_id' => $projectId,
                    'table_name' => $tableName,
                    'form_code'  => $formCode
                ]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Returns SQL filter fragment and parameters for filtering queries by project_id.
     *
     * @param string $alias
     * @return array [string $sqlFragment, array $params]
     */
    public static function sqlFilter(string $alias = ''): array {
        $col = $alias !== '' ? "{$alias}.project_id" : 'project_id';
        return ["{$col} = ?", [self::getId()]];
    }
}
