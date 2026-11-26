<?php
declare(strict_types=1);

/**
 * Role-by-form permission check for the generic CRUD endpoint (api/crud.php).
 *
 * A data table is reachable through the forms that point at it (nu_forms.form_table) in the active
 * project. The role needs the matching flag (view/add/edit/delete) in nu_role_form_permissions on at
 * least one of those forms, or on the '*' wildcard row. globeadmin always passes.
 *
 * A table that no form in the project points at is denied, because there is nothing to grant on.
 * Set $nuConfig['crudAllowUnmappedTables'] = true to allow read-only access to such tables.
 */
final class NuCrudGuard
{
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

    public static function allowed(string $method, string $table, string $role, int $projectId): bool
    {
        if (strtolower($role) === 'globeadmin') return true;

        $action = self::actionFor($method);
        $col = ['view' => 'rfp_can_view', 'add' => 'rfp_can_add', 'edit' => 'rfp_can_edit', 'delete' => 'rfp_can_delete'][$action];
        $db = NuDatabase::getInstance();

        $forms = $db->fetchAll(
            'SELECT form_code FROM nu_forms WHERE LOWER(form_table) = :t AND (project_id = :p OR project_id IS NULL)',
            [':t' => strtolower($table), ':p' => $projectId]
        );
        if (!$forms) {
            $open = (bool)($GLOBALS['nuConfig']['crudAllowUnmappedTables'] ?? false);
            return $open && $action === 'view';
        }

        $codes = array_map(static fn($r) => (string)$r['form_code'], $forms);
        $codes[] = '*';
        $in = implode(',', array_fill(0, count($codes), '?'));
        $row = $db->fetchOne(
            "SELECT 1 AS ok FROM nu_role_form_permissions WHERE rfp_role_code = ? AND rfp_form_code IN ($in) AND {$col} = 1 LIMIT 1",
            array_merge([$role], $codes)
        );
        return (bool)$row;
    }
}
