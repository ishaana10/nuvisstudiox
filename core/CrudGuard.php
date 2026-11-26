<?php
declare(strict_types=1);

/**
 * One data-access guard for every endpoint that reads or writes project data.
 *
 * Checks, in order:
 *  1. platform tables (nu_*) are for globeadmin only;
 *  2. the table must belong to the active project (ProjectContext::canAccessTable);
 *  3. the role needs the action (view/add/edit/delete/export) in nu_role_form_permissions on a form
 *     in the active project that uses the table, or on the '*' row. globeadmin always passes.
 *
 * A table that no form in the project uses is denied, because there is nothing to grant on.
 * $nuConfig['crudAllowUnmappedTables'] = true allows read-only (view) access to such tables.
 * Lookups pass $requireForm = false: they only need the project check.
 */
final class NuCrudGuard
{
    private const FLAGS = [
        'view' => 'rfp_can_view', 'add' => 'rfp_can_add', 'edit' => 'rfp_can_edit',
        'delete' => 'rfp_can_delete', 'export' => 'rfp_can_export',
    ];

    public static function actionFor(string $method): string
    {
        switch (strtoupper($method)) {
            case 'POST':   return 'add';
            case 'PUT':
            case 'PATCH':  return 'edit';
            case 'DELETE': return 'delete';
            default:       return 'view';
        }
    }

    /** HTTP-method flavour used by api/crud.php. */
    public static function allowed(string $method, string $table, string $role, int $projectId): bool
    {
        return self::allowedAction(self::actionFor($method), $table, $role, $projectId);
    }

    public static function allowedAction(string $action, string $table, string $role, int $projectId): bool
    {
        if (strtolower($role) === 'globeadmin') return true;
        if (!isset(self::FLAGS[$action])) return false;

        $forms = NuDatabase::getInstance()->fetchAll(
            'SELECT form_code FROM nu_forms WHERE LOWER(form_table) = :t AND (project_id = :p OR project_id IS NULL)',
            [':t' => strtolower($table), ':p' => $projectId]
        );
        if (!$forms) {
            return (bool)($GLOBALS['nuConfig']['crudAllowUnmappedTables'] ?? false) && $action === 'view';
        }
        return self::roleHas($action, $role, array_map(static fn($r) => (string)$r['form_code'], $forms));
    }

    /** @return string|null error message, null when allowed */
    public static function authorizeTable(string $table, string $action, string $role, int $projectId, bool $requireForm = true): ?string
    {
        if (strtolower($role) === 'globeadmin') return null;
        if (str_starts_with(strtolower($table), 'nu_')) {
            return 'Direct access to core system metadata tables is restricted.';
        }
        require_once __DIR__ . '/ProjectContext.php';
        if (!ProjectContext::canAccessTable($table, $projectId, $role)) {
            return 'This table does not belong to the active project.';
        }
        if ($requireForm && !self::allowedAction($action, $table, $role, $projectId)) {
            return 'Your role does not have permission to ' . $action . ' this data.';
        }
        return null;
    }

    /** @return string|null error message, null when allowed */
    public static function authorizeForm(string $formCode, string $action, string $role, int $projectId): ?string
    {
        if (strtolower($role) === 'globeadmin') return null;
        if (!isset(self::FLAGS[$action])) return 'Unknown action.';
        $form = NuDatabase::getInstance()->fetchOne(
            'SELECT form_code FROM nu_forms WHERE form_code = :c AND (project_id = :p OR project_id IS NULL)',
            [':c' => $formCode, ':p' => $projectId]
        );
        if (!$form) return 'Form not found in the active project.';
        return self::roleHas($action, $role, [$formCode])
            ? null
            : 'Your role does not have permission to ' . $action . ' this form.';
    }

    /** Send a 403 JSON answer and stop. */
    public static function deny(string $message): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    private static function roleHas(string $action, string $role, array $formCodes): bool
    {
        $col = self::FLAGS[$action];
        $codes = $formCodes;
        $codes[] = '*';
        $in = implode(',', array_fill(0, count($codes), '?'));
        $row = NuDatabase::getInstance()->fetchOne(
            "SELECT 1 AS ok FROM nu_role_form_permissions WHERE rfp_role_code = ? AND rfp_form_code IN ($in) AND {$col} = 1 LIMIT 1",
            array_merge([$role], $codes)
        );
        return (bool)$row;
    }
}
