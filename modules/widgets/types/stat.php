<?php
declare(strict_types=1);

return [
    'type'  => 'stat',
    'label' => 'Stat / KPI',
    'icon'  => 'hash',
    'group' => 'Metrics',
    'defaults' => ['width' => 1, 'height' => 1, 'config' => ['sql' => '', 'subtitle' => '', 'color' => 'primary']],
    'fields' => [
        ['key' => 'sql', 'label' => 'SQL query', 'kind' => 'sql', 'required' => true, 'help' => 'Return one row with a "value" column (or any single column).'],
        ['key' => 'subtitle', 'label' => 'Subtitle', 'kind' => 'text'],
        ['key' => 'color', 'label' => 'Tone', 'kind' => 'select', 'options' => ['primary', 'success', 'warning', 'error', 'info']],
        ['key' => 'warn_at', 'label' => 'Warn at (≥)', 'kind' => 'number', 'help' => 'Value that turns the number amber.'],
        ['key' => 'error_at', 'label' => 'Error at (≥)', 'kind' => 'number', 'help' => 'Value that turns the number red.'],
        ['key' => 'link_module', 'label' => 'Drill-down form code', 'kind' => 'text'],
        ['key' => 'link_mode', 'label' => 'Open drill-down as', 'kind' => 'select', 'options' => ['inline', 'popup']],
        ['key' => 'link_url', 'label' => 'Or drill-down URL', 'kind' => 'url'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $sql = trim((string)($cfg['sql'] ?? ''));
        if ($sql === '') return $R::emptyHint('Not configured yet.');
        $val = (float)$R::firstValue(NuWidgetSql::run($ctx['db'], $sql, $ctx['userId'], $ctx['role']));

        $state = '';
        if (isset($cfg['error_at']) && $cfg['error_at'] !== '' && $val >= (float)$cfg['error_at']) $state = ' is-error';
        elseif (isset($cfg['warn_at']) && $cfg['warn_at'] !== '' && $val >= (float)$cfg['warn_at']) $state = ' is-warn';

        $tones = ['primary', 'success', 'warning', 'error', 'info'];
        $color = (string)($cfg['color'] ?? 'primary');
        $tone  = in_array($color, $tones, true) ? $color : 'primary';
        $sub   = trim((string)($cfg['subtitle'] ?? ''));

        $attrs = $R::linkAttrs(['module' => $cfg['link_module'] ?? '', 'mode' => $cfg['link_mode'] ?? 'inline', 'url' => $cfg['link_url'] ?? '']);
        $link = '';
        if ($attrs !== '') {
            $tag  = str_starts_with($attrs, 'href=') ? 'a' : 'button type="button"';
            $end  = $tag === 'a' ? 'a' : 'button';
            $link = '<' . $tag . ' class="nu-wg-link" ' . $attrs . ' aria-label="View records"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></' . $end . '>';
        }
        return '<div class="nu-wg-stat tone-' . $tone . $state . '"><div class="nu-wg-stat-main">'
             . '<div class="nu-wg-stat-value">' . number_format($val) . '</div>'
             . ($sub !== '' ? '<div class="nu-wg-stat-sub">' . $R::esc($sub) . '</div>' : '')
             . '</div>' . $link . '</div>';
    },
];
