<?php
declare(strict_types=1);

return [
    'type'  => 'gauge',
    'label' => 'Gauge',
    'icon'  => 'gauge',
    'group' => 'Metrics',
    'defaults' => ['width' => 1, 'height' => 1, 'config' => ['sql' => '', 'label' => '']],
    'fields' => [
        ['key' => 'sql', 'label' => 'SQL query', 'kind' => 'sql', 'required' => true, 'help' => 'Return "done" and "total" columns.'],
        ['key' => 'label', 'label' => 'Label', 'kind' => 'text'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $sql = trim((string)($cfg['sql'] ?? ''));
        if ($sql === '') return $R::emptyHint('Not configured yet.');
        $rows  = NuWidgetSql::run($ctx['db'], $sql, $ctx['userId'], $ctx['role']);
        $total = (float)($rows[0]['total'] ?? 0);
        $done  = (float)($rows[0]['done'] ?? 0);
        $pct   = $total > 0 ? max(0, min(100, (int)round($done / $total * 100))) : 0;
        $label = trim((string)($cfg['label'] ?? ''));
        $arc   = 125.66; // length of the half-circle path (π × 40)
        $fill  = round($arc * $pct / 100, 2);
        return '<div class="nu-wg-gauge" data-pct="' . $pct . '" role="img" aria-label="' . $R::esc(($label !== '' ? $label . ': ' : '') . $pct . '%') . '">'
             . '<svg viewBox="0 0 100 56" aria-hidden="true"><path class="nu-wg-gauge-track" d="M10 50 A40 40 0 0 1 90 50" fill="none" stroke-width="9" stroke-linecap="round"/>'
             . '<path class="nu-wg-gauge-fill" d="M10 50 A40 40 0 0 1 90 50" fill="none" stroke-width="9" stroke-linecap="round" stroke-dasharray="' . $fill . ' ' . $arc . '"/></svg>'
             . '<div class="nu-wg-gauge-value">' . $pct . '%</div>' . ($label !== '' ? '<div class="nu-wg-stat-sub">' . $R::esc($label) . '</div>' : '') . '</div>';
    },
];
