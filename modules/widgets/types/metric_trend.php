<?php
declare(strict_types=1);
require_once __DIR__ . '/_chart.php';

return [
    'type'  => 'metric_trend',
    'label' => 'Metric Trend',
    'icon'  => 'trending',
    'group' => 'Metrics',
    'defaults' => ['width' => 2, 'height' => 1, 'config' => ['sql' => '', 'subtitle' => '']],
    'fields' => [
        ['key' => 'sql', 'label' => 'SQL query', 'kind' => 'sql', 'required' => true, 'help' => 'Columns: value, optional prev_value; extra rows with label/value draw the sparkline.'],
        ['key' => 'subtitle', 'label' => 'Subtitle', 'kind' => 'text'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $sql = trim((string)($cfg['sql'] ?? ''));
        if ($sql === '') return $R::emptyHint('Not configured yet.');
        $rows = NuWidgetSql::run($ctx['db'], $sql, $ctx['userId'], $ctx['role']);
        $val  = (float)($rows[0]['value'] ?? 0);
        $prev = (float)($rows[0]['prev_value'] ?? $cfg['prev_value'] ?? 0);
        $diff = $prev > 0 ? (($val - $prev) / $prev) * 100 : 0.0;
        $dir  = $diff >= 0 ? 'up' : 'down';
        $sub  = trim((string)($cfg['subtitle'] ?? ''));
        $spark = count($rows) > 1 ? nu_wg_chart_markup('sparkline', $rows, (string)($widget['widget_title'] ?? ''), true) : '';
        return '<div class="nu-wg-trend"><div class="nu-wg-trend-main">'
             . '<div class="nu-wg-stat-value">' . number_format($val) . '</div>'
             . '<div class="nu-wg-delta is-' . $dir . '">' . ($dir === 'up' ? '↑' : '↓') . ' ' . abs(round($diff, 1)) . '% <span>vs prev period</span></div>'
             . ($sub !== '' ? '<div class="nu-wg-stat-sub">' . $R::esc($sub) . '</div>' : '')
             . '</div>' . $spark . '</div>';
    },
];
