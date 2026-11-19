<?php
declare(strict_types=1);
/**
 * NuWidgetRegistry — dashboard widget types, one file per type.
 *
 * A type file in modules/widgets/types/ (not starting with "_") returns:
 *   [
 *     'type' => 'stat', 'label' => 'Stat / KPI', 'icon' => 'hash', 'group' => 'Metrics',
 *     'defaults' => ['width' => 1, 'height' => 1, 'config' => [...]],
 *     'fields'   => [['key','label','kind','help'?,'required'?,'options'?], ...],
 *     'render'   => fn(array $widget, array $cfg, array $ctx): string,   // $ctx = db, userId, role
 *   ]
 * Field kinds: sql, text, textarea, number, select, color, lines, html, url.
 * Plugins can add types with NuWidgetRegistry::register($definition).
 */
class NuWidgetRegistry {
    public const FIELD_KINDS = ['sql', 'text', 'textarea', 'number', 'select', 'color', 'lines', 'html', 'url'];

    /** @var array<string,array>|null */
    private static ?array $loaded = null;
    /** @var array<string,array> */
    private static array $registered = [];

    public static function reset(): void {
        self::$loaded = null;
        self::$registered = [];
    }

    /** @return array<string,array> definitions keyed by type */
    public static function all(): array {
        if (self::$loaded === null) {
            self::$loaded = [];
            $dir = dirname(__DIR__) . '/modules/widgets/types';
            $files = glob($dir . '/*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                if (str_starts_with(basename($file), '_')) continue;
                try {
                    $def = require $file;
                } catch (Throwable $e) {
                    error_log('[widgets] type file ' . basename($file) . ' failed: ' . $e->getMessage());
                    continue;
                }
                $problems = is_array($def) ? self::validate($def) : ['did not return an array'];
                if ($problems) {
                    error_log('[widgets] type file ' . basename($file) . ' invalid: ' . implode('; ', $problems));
                    continue;
                }
                self::$loaded[$def['type']] = $def;
            }
        }
        return self::$loaded + self::$registered;
    }

    public static function get(string $type): ?array {
        return self::all()[$type] ?? null;
    }

    /** @throws InvalidArgumentException when the definition breaks the contract */
    public static function register(array $def): void {
        $problems = self::validate($def);
        if ($problems) {
            throw new InvalidArgumentException('Invalid widget type: ' . implode('; ', $problems));
        }
        self::$registered[$def['type']] = $def;
    }

    /** @return string[] problems; empty means valid */
    public static function validate(array $def): array {
        $p = [];
        if (!isset($def['type']) || !is_string($def['type']) || !preg_match('/^[a-z][a-z0-9_]*$/', $def['type'])) $p[] = 'type must match [a-z][a-z0-9_]*';
        foreach (['label', 'group', 'icon'] as $k) {
            if (!isset($def[$k]) || !is_string($def[$k]) || $def[$k] === '') $p[] = "{$k} is required";
        }
        $d = $def['defaults'] ?? null;
        if (!is_array($d) || !is_int($d['width'] ?? null) || !is_int($d['height'] ?? null) || !is_array($d['config'] ?? null)) {
            $p[] = 'defaults needs int width, int height and array config';
        }
        if (!isset($def['fields']) || !is_array($def['fields'])) {
            $p[] = 'fields must be an array';
        } else {
            foreach ($def['fields'] as $i => $f) {
                if (!is_array($f) || empty($f['key']) || empty($f['label']) || !in_array($f['kind'] ?? '', self::FIELD_KINDS, true)) {
                    $p[] = "fields[{$i}] needs key, label and a valid kind";
                }
            }
        }
        if (!isset($def['render']) || !is_callable($def['render'])) $p[] = 'render must be callable';
        return $p;
    }

    /**
     * Render one widget row to an HTML fragment. Never throws.
     * @return array{ok:bool,html:string}
     */
    public static function renderWidget(array $widget, NuDatabase $db, int|string $userId, string $role): array {
        $type = (string)($widget['widget_type'] ?? '');
        $def  = self::get($type);
        if ($def === null) {
            return ['ok' => false, 'html' => self::errorFragment('Unknown widget type: ' . $type)];
        }
        $cfg = json_decode((string)($widget['widget_config'] ?? '{}'), true);
        $cfg = is_array($cfg) ? $cfg : [];
        try {
            $html = ($def['render'])($widget, $cfg, ['db' => $db, 'userId' => $userId, 'role' => $role]);
            return ['ok' => true, 'html' => (string)$html];
        } catch (Throwable $e) {
            error_log('[widgets] render id=' . ($widget['widget_id'] ?? '?') . ' type=' . $type . ': ' . $e->getMessage());
            $detail = strtolower($role) === 'globeadmin' ? $e->getMessage() : 'This widget could not load.';
            return ['ok' => false, 'html' => self::errorFragment($detail)];
        }
    }

    // ── Markup helpers shared by type files ────────────────────────────────

    public static function esc(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** http(s) URL, or a site-relative path / query / hash. Anything else → ''. */
    public static function safeUrl(string $url): string {
        $url = trim($url);
        if (preg_match('#^https?://[^\s<>"\']+$#i', $url)) return $url;
        if (preg_match('#^(/(?!/)|\?|\#)[^\s<>"\']*$#', $url)) return $url;
        return '';
    }

    /** Font Awesome class (legacy) or plain text/emoji, escaped. */
    /** Built-in stroke icon set (24x24). Names are what a widget type's 'icon' key and the builder use. */
    private const SVG_ICONS = [
        'activity'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'bolt'      => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'code'      => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'gauge'     => '<path d="M12 14l4-4"/><path d="M3.3 17a10 10 0 1 1 17.4 0"/>',
        'globe'     => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 0 1 0 20a15 15 0 0 1 0-20"/>',
        'hash'      => '<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'megaphone' => '<path d="M3 11v2a1 1 0 0 0 1 1h3l8 5V5L7 10H4a1 1 0 0 0-1 1z"/><path d="M18.5 9a4 4 0 0 1 0 6"/>',
        'progress'  => '<rect x="3" y="10" width="18" height="4" rx="2"/><line x1="3" y1="12" x2="12" y2="12"/>',
        'table'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="9" y1="4" x2="9" y2="20"/>',
        'trending'  => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'chart'     => '<line x1="6" y1="20" x2="6" y2="12"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="18" y1="20" x2="18" y2="9"/>',
        'chart-bar' => '<rect x="4" y="11" width="4" height="9" rx="1"/><rect x="10" y="5" width="4" height="15" rx="1"/><rect x="16" y="14" width="4" height="6" rx="1"/>',
        'chart-area'=> '<path d="M3 18l5-7 4 4 5-8 4 6v5H3z"/>',
        'radar'     => '<polygon points="12 2 21 9 18 20 6 20 3 9"/><polyline points="12 7 16 10 14.5 15 9.5 15 8 10 12 7"/>',
        'donut'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/>',
        'pulse'     => '<path d="M3 12h4l2-5 4 10 2-5h6"/>',
        'pie'       => '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
        'list'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'star'      => '<polygon points="12 2 15.1 8.6 22 9.6 17 14.5 18.2 21.5 12 18.2 5.8 21.5 7 14.5 2 9.6 8.9 8.6 12 2"/>',
        'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    ];

    /** Inline SVG for a built-in icon name; unknown names fall back to a generic square. */
    public static function svgIcon(string $name): string {
        $body = self::SVG_ICONS[$name] ?? '<rect x="4" y="4" width="16" height="16" rx="3"/>';
        return '<svg class="nu-wg-svgicon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
    }

    /** @return string[] names available to the builder's icon picker */
    public static function svgIconNames(): array { return array_keys(self::SVG_ICONS); }

    public static function iconHtml(string $icon): string {
        $icon = trim($icon);
        if ($icon === '') return '';
        if (isset(self::SVG_ICONS[$icon])) return self::svgIcon($icon);
        if (preg_match('/^(fas?|far|fab|fa)\s+fa-[a-z0-9-]+$|^fa-[a-z0-9-]+$/', $icon)) {
            $cls = str_contains($icon, ' ') ? $icon : 'fas ' . $icon;
            return '<i class="' . self::esc($cls) . '" aria-hidden="true"></i>';
        }
        if (preg_match('/^(fas?|far|fab|fa)\b|^fa-/', $icon)) return ''; // Font Awesome-looking but malformed
        if (preg_match('/^[A-Za-z0-9_\- ]+$/', $icon)) return ''; // a plain word that isn't a known icon (old seeds): use the type's default
        return self::esc($icon);
    }

    public static function emptyHint(string $text = 'Not configured yet.'): string {
        return '<div class="nu-wg-empty"><span>' . self::esc($text) . '</span></div>';
    }

    public static function errorFragment(string $message): string {
        return '<div class="nu-wg-error" role="alert"><span class="nu-wg-error-msg">' . self::esc($message)
             . '</span><button type="button" class="nu-wg-retry" data-nu-retry>Retry</button></div>';
    }

    /** First value of the first row, preferring a column named "value". */
    public static function firstValue(array $rows) {
        if (!$rows) return 0;
        return $rows[0]['value'] ?? reset($rows[0]);
    }

    /** Link attributes for a module/url target, or '' when neither is usable. */
    public static function linkAttrs(array $item): string {
        $mod = trim((string)($item['module'] ?? ''));
        if ($mod !== '') {
            $mode = (($item['mode'] ?? 'inline') === 'popup') ? 'popup' : 'inline';
            return 'data-nu-drill="' . self::esc($mod) . '" data-nu-mode="' . $mode . '"';
        }
        $url = self::safeUrl((string)($item['url'] ?? ''));
        return $url !== '' ? 'href="' . self::esc($url) . '" target="_blank" rel="noopener noreferrer"' : '';
    }
}

// ── Legacy helpers (kept so existing callers and tests keep working) ───────────
if (!function_exists('wu_accent')) {
    function wu_accent(string $color): string {
        return match ($color) {
            'success' => '#22c55e', 'warning' => '#f59e0b', 'error' => '#ef4444', 'info' => '#3b82f6',
            default   => '#0ea5e9',
        };
    }
}
if (!function_exists('wu_chart_type')) {
    function wu_chart_type(string $t): string {
        return match ($t) {
            'chart_pie' => 'pie', 'chart_doughnut' => 'doughnut', 'chart_radar' => 'radar',
            'chart_line', 'chart_area', 'sparkline' => 'line',
            default => 'bar',
        };
    }
}
if (!function_exists('wu_type_accent')) {
    function wu_type_accent(string $type): string {
        return match ($type) {
            'metric_trend' => '#0ea5e9', 'sparkline' => '#0284c7', 'chart_bar' => '#006494', 'chart_line' => '#7a39bb',
            'chart_area' => '#8b5cf6', 'chart_pie' => '#da7101', 'chart_doughnut' => '#f59e0b', 'chart_radar' => '#ec4899',
            'table' => '#437a22', 'list' => '#006494', 'quick_actions' => '#10b981', 'activity' => '#6366f1',
            'progress' => '#964219', 'iframe' => '#64748b', 'custom' => '#a12c7b',
            default => '#01696f',
        };
    }
}
