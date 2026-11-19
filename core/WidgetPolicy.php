<?php
declare(strict_types=1);
/**
 * NuWidgetPolicy — who may call which dashboard-widget API action.
 * "Developer" = the globeadmin role. Everyone signed in may manage only their
 * own view (prefs/settings) and read what is visible to them.
 */
class NuWidgetPolicy {
    private const DEVELOPER_ONLY = [
        'run_sql', 'list_tables', 'designer_load', 'types',
        'role_add', 'role_update', 'role_remove', 'role_reorder',
    ];
    private const ANY_AUTHENTICATED = [
        'list', 'render', 'prefs_save', 'prefs_reset', 'settings_save',
    ];

    public static function can(string $action, string $role): bool {
        $role = strtolower(trim($role));
        if ($role === '') return false;
        if (in_array($action, self::DEVELOPER_ONLY, true)) return $role === 'globeadmin';
        return in_array($action, self::ANY_AUTHENTICATED, true);
    }
}
