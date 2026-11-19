<?php
declare(strict_types=1);
/**
 * NuWidgetStore — persistence for dashboard widgets visibility and per-user
 * overrides. Portable SQL only (MySQL/MariaDB and SQLite); upserts are done as
 * select-then-write so the same code path is unit-tested.
 */
class NuWidgetStore {
    private const PREF_COLUMNS = ['hidden', 'position', 'width', 'height', 'accent'];

    /** Role codes are compared lowercase everywhere (the dashboard shell lowercases the session role). */
    public static function normalizeRole(string $role): string {
        return strtolower(trim($role));
    }

    /** Active role widgets for $role plus the user's own personal widgets. */
    public static function visibleWidgets(NuDatabase $db, int $userId, string $role): array {
        return $db->fetchAll(
            'SELECT * FROM nu_dashboard_widgets
              WHERE widget_active = 1
                AND ((widget_user_id IS NULL AND widget_role = ?) OR widget_user_id = ?)
              ORDER BY widget_position, widget_id',
            [$role, $userId]
        ) ?: [];
    }

    /** Active role-level widgets for one role (designer view). */
    public static function roleWidgets(NuDatabase $db, string $role): array {
        return $db->fetchAll(
            'SELECT * FROM nu_dashboard_widgets
              WHERE widget_active = 1 AND widget_user_id IS NULL AND widget_role = ?
              ORDER BY widget_position, widget_id',
            [$role]
        ) ?: [];
    }

    /** @return array<int,array> prefs keyed by widget_id */
    public static function loadPrefs(NuDatabase $db, int $userId): array {
        $out = [];
        foreach ($db->fetchAll('SELECT * FROM nu_dashboard_user_prefs WHERE user_id = ?', [$userId]) as $row) {
            $out[(int)$row['widget_id']] = $row;
        }
        return $out;
    }

    /**
     * Upsert already-sanitised items (see NuWidgetPrefs::sanitizeSaveItems).
     * Only the supplied keys change; other stored values are kept.
     */
    public static function savePrefs(NuDatabase $db, int $userId, array $items): void {
        $now = date('Y-m-d H:i:s');
        foreach ($items as $it) {
            $id = (int)$it['id'];
            $vals = array_intersect_key($it, array_flip(self::PREF_COLUMNS));
            $exists = $db->fetchOne('SELECT pref_id FROM nu_dashboard_user_prefs WHERE user_id = ? AND widget_id = ?', [$userId, $id]);
            if ($exists) {
                if (!$vals) continue;
                $set = implode(', ', array_map(static fn($c) => "{$c} = ?", array_keys($vals)));
                $db->query("UPDATE nu_dashboard_user_prefs SET {$set}, updated_at = ? WHERE user_id = ? AND widget_id = ?",
                    array_merge(array_values($vals), [$now, $userId, $id]));
            } else {
                $cols = array_merge(['user_id', 'widget_id'], array_keys($vals), ['updated_at']);
                $marks = implode(', ', array_fill(0, count($cols), '?'));
                $db->query('INSERT INTO nu_dashboard_user_prefs (' . implode(', ', $cols) . ") VALUES ({$marks})",
                    array_merge([$userId, $id], array_values($vals), [$now]));
            }
        }
    }

    /** Remove the user's overrides and look settings. Returns pref rows deleted. */
    public static function resetPrefs(NuDatabase $db, int $userId): int {
        $n = $db->query('DELETE FROM nu_dashboard_user_prefs WHERE user_id = ?', [$userId])->rowCount();
        $db->query('DELETE FROM nu_dashboard_user_settings WHERE user_id = ?', [$userId]);
        return (int)$n;
    }

    public static function loadSettings(NuDatabase $db, int $userId): array {
        $row = $db->fetchOne('SELECT settings FROM nu_dashboard_user_settings WHERE user_id = ?', [$userId]);
        $decoded = $row ? json_decode((string)$row['settings'], true) : [];
        return NuWidgetPrefs::normalizeSettings(is_array($decoded) ? $decoded : []);
    }

    /** Merge supplied keys over the stored settings, normalise, persist, return the result. */
    public static function saveSettings(NuDatabase $db, int $userId, array $in): array {
        $has = $db->fetchOne('SELECT 1 FROM nu_dashboard_user_settings WHERE user_id = ?', [$userId]);
        $merged = NuWidgetPrefs::normalizeSettings(array_merge($has ? self::loadSettings($db, $userId) : [], $in));
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');
        if ($has) {
            $db->query('UPDATE nu_dashboard_user_settings SET settings = ?, updated_at = ? WHERE user_id = ?', [$json, $now, $userId]);
        } else {
            $db->query('INSERT INTO nu_dashboard_user_settings (user_id, settings, updated_at) VALUES (?, ?, ?)', [$userId, $json, $now]);
        }
        return $merged;
    }
}
