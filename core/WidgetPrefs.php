<?php
declare(strict_types=1);
/**
 * NuWidgetPrefs — pure helpers for per-user dashboard overrides.
 * No database access: callers pass rows in and persist what comes out.
 */
class NuWidgetPrefs {
    public const ACCENT_TOKENS = ['teal', 'blue', 'violet', 'rose', 'amber', 'green', 'slate'];
    public const DENSITIES     = ['comfortable', 'compact'];
    public const CARD_STYLES   = ['soft', 'flat', 'outlined'];

    /** Lowercase #rrggbb or a named palette token; anything else is rejected (null). */
    public static function validAccent(?string $v): ?string {
        if ($v === null) return null;
        if (preg_match('/^#[0-9a-f]{6}$/', $v) === 1) return $v;
        return in_array($v, self::ACCENT_TOKENS, true) ? $v : null;
    }

    /** @return array{accent:?string,density:string,card_style:string} */
    public static function normalizeSettings(array $in): array {
        $density = (string)($in['density'] ?? '');
        $style   = (string)($in['card_style'] ?? '');
        return [
            'accent'     => self::validAccent(isset($in['accent']) ? (string)$in['accent'] : null),
            'density'    => in_array($density, self::DENSITIES, true) ? $density : 'comfortable',
            'card_style' => in_array($style, self::CARD_STYLES, true) ? $style : 'soft',
        ];
    }

    /**
     * Apply a user's prefs to widget rows.
     *
     * @param array<int,array> $widgets        widget rows (widget_id, widget_position, widget_locked, widget_width, widget_height)
     * @param array<int,array> $prefsByWidgetId [widget_id => [hidden, position, width, height, accent]]
     * @return array{visible:array,hidden:array}
     */
    public static function resolve(array $widgets, array $prefsByWidgetId): array {
        $visible = [];
        $hidden  = [];
        foreach ($widgets as $w) {
            $id = (int)($w['widget_id'] ?? 0);
            $p  = $prefsByWidgetId[$id] ?? [];
            $w['widget_width']  = self::clamp((int)($p['width']  ?? $w['widget_width']  ?? 2), 1, 4);
            $w['widget_height'] = self::clamp((int)($p['height'] ?? $w['widget_height'] ?? 1), 1, 3);
            $w['accent']        = self::validAccent(isset($p['accent']) ? (string)$p['accent'] : null);
            $w['_sort']         = isset($p['position']) ? (int)$p['position'] : (int)($w['widget_position'] ?? 0);
            $isLocked = (int)($w['widget_locked'] ?? 0) === 1;
            if (!empty($p['hidden']) && !$isLocked) {
                $hidden[] = $w;
            } else {
                $visible[] = $w;
            }
        }
        $cmp = static fn(array $a, array $b): int => [$a['_sort'], (int)$a['widget_id']] <=> [$b['_sort'], (int)$b['widget_id']];
        usort($visible, $cmp);
        usort($hidden, $cmp);
        foreach ($visible as &$v) unset($v['_sort']);
        foreach ($hidden as &$h)  unset($h['_sort']);
        return ['visible' => $visible, 'hidden' => $hidden];
    }

    /**
     * Validate a batch of client-submitted pref items. Unknown widget ids are
     * dropped, only supplied keys are kept, values are clamped/validated, and
     * `hidden` is forced to 0 for locked widgets.
     *
     * @param array<int,array> $widgetsById widgets the user may customise, keyed by widget_id
     * @return array<int,array>
     */
    public static function sanitizeSaveItems(array $items, array $widgetsById): array {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $id = (int)($it['id'] ?? 0);
            if ($id <= 0 || !isset($widgetsById[$id])) continue;
            $clean = ['id' => $id];
            if (array_key_exists('hidden', $it)) {
                $locked = (int)($widgetsById[$id]['widget_locked'] ?? 0) === 1;
                $clean['hidden'] = (!$locked && !empty($it['hidden'])) ? 1 : 0;
            }
            if (array_key_exists('position', $it)) $clean['position'] = self::clamp((int)$it['position'], -32768, 32767);
            if (array_key_exists('width', $it))    $clean['width']    = self::clamp((int)$it['width'], 1, 4);
            if (array_key_exists('height', $it))   $clean['height']   = self::clamp((int)$it['height'], 1, 3);
            if (array_key_exists('accent', $it))   $clean['accent']   = self::validAccent(is_string($it['accent']) ? $it['accent'] : null);
            $out[] = $clean;
        }
        return $out;
    }

    private static function clamp(int $v, int $min, int $max): int {
        return max($min, min($max, $v));
    }
}
