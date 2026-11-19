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
    public static function renderWidget(array $widget, NuDatabase $db, int $userId, string $role): array {
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
    public static function iconHtml(string $icon): string {
        $icon = trim($icon);
        if ($icon === '') return '';
        if (preg_match('/^(fas?|far|fab|fa)\s+fa-[a-z0-9-]+$|^fa-[a-z0-9-]+$/', $icon)) {
            $cls = str_contains($icon, ' ') ? $icon : 'fas ' . $icon;
            return '<i class="' . self::esc($cls) . '" aria-hidden="true"></i>';
        }
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
