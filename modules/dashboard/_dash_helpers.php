<?php
declare(strict_types=1);
/**
 * modules/dashboard/_dash_helpers.php
 * Small rendering helpers shared by dashboard.php (admin) and dashboard_user.php.
 * Included with require_once — safe to include from either.
 */

if (!function_exists('nu_dash_h')) {
    function nu_dash_h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    /** 24×24 stroke icon paths (inherit currentColor). */
    function nu_dash_icon(string $name): string {
        static $paths = [
            'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'form'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
            'report'   => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
            'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'plus'     => '<path d="M12 5v14M5 12h14"/>',
            'refresh'  => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
            'query'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
            'user-plus'=> '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>',
            'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'search'   => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
            'arrow'    => '<path d="M5 12h14M13 5l7 7-7 7"/>',
            'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
             . ($paths[$name] ?? '') . '</svg>';
    }

    /** "just now", "5m ago", "3h ago", "2d ago", else "Mar 4". */
    function nu_dash_ago(?string $datetime): string {
        $ts = $datetime ? strtotime($datetime) : false;
        if ($ts === false) return '';
        $d = time() - $ts;
        if ($d < 45)        return 'just now';
        if ($d < 3600)      return max(1, (int)round($d / 60)) . 'm ago';
        if ($d < 86400)     return (int)round($d / 3600) . 'h ago';
        if ($d < 86400 * 7) return (int)round($d / 86400) . 'd ago';
        return date('M j', $ts);
    }

    function nu_dash_greeting(): string {
        $h = (int)date('G');
        return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
    }

    /** Inline-SVG sparkline from a list of numbers. Empty/flat series render a baseline. */
    function nu_dash_sparkline(array $values, int $w = 96, int $h = 32): string {
        $n = count($values);
        if ($n < 2) return '';
        $max = max($values); $min = min($values);
        $range = max(1, $max - $min);
        $pts = [];
        foreach (array_values($values) as $i => $v) {
            $x = round($i * ($w - 4) / ($n - 1) + 2, 1);
            $y = $max === $min ? $h - 4 : round($h - 3 - (($v - $min) / $range) * ($h - 8), 1);
            $pts[] = $x . ',' . $y;
        }
        $line = implode(' ', $pts);
        $area = '2,' . $h . ' ' . $line . ' ' . ($w - 2) . ',' . $h;
        return '<svg class="nu-dash-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
             . '<polygon points="' . $area . '" class="nu-dash-spark-area"/>'
             . '<polyline points="' . $line . '" class="nu-dash-spark-line"/></svg>';
    }

    /** Page hero: greeting, context line, controls. $subtitle is plain text. */
    function nu_dash_hero(string $name, string $subtitle, bool $showControls = true): string {
        $project = (string)($_SESSION['nu_project_code'] ?? '');
        ob_start(); ?>
<section class="nu-dash-hero">
    <div class="nu-dash-hero-text">
        <h1 class="nu-dash-hello"><?= nu_dash_h(nu_dash_greeting()) ?>, <?= nu_dash_h($name) ?></h1>
        <p class="nu-dash-sub">
            <?= nu_dash_h(date('l, j F Y')) ?>
            <?php if ($subtitle !== ''): ?><span class="nu-dash-dot" aria-hidden="true">·</span><?= nu_dash_h($subtitle) ?><?php endif; ?>
            <?php if ($project !== ''): ?><span class="nu-dash-dot" aria-hidden="true">·</span><span class="nu-dash-chip"><?= nu_dash_h($project) ?></span><?php endif; ?>
        </p>
    </div>
    <?php if ($showControls): ?>
    <div class="nu-dash-hero-actions">
        <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm nu-dash-ctl" data-nu-cmdk title="Search pages and actions">
            <?= nu_dash_icon('search') ?><span>Search</span>
        </button>
        <label class="nu-dash-auto" title="Reload this dashboard every minute">
            <input type="checkbox" data-nu-dash-auto> <span>Auto-refresh</span>
        </label>
        <button type="button" class="nu-btn nu-btn-ghost nu-btn-sm nu-dash-ctl" data-nu-dash-refresh title="Refresh dashboard">
            <?= nu_dash_icon('refresh') ?><span>Refresh</span>
        </button>
    </div>
    <?php endif; ?>
</section>
<?php
        return (string)ob_get_clean();
    }
}
