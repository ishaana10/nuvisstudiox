<?php
declare(strict_types=1);

return [
    'type'  => 'progress',
    'label' => 'Progress Bar',
    'icon'  => 'progress',
    'group' => 'Metrics',
    'defaults' => ['width' => 2, 'height' => 1, 'config' => ['sql' => '', 'label' => '']],
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
        $label = trim((string)($cfg['label'] ?? '')) ?: ((int)$done . ' / ' . (int)$total);
        return '<div class="nu-wg-progress"><div class="nu-wg-progress-head"><span>' . $R::esc($label) . '</span><span>' . $pct . '%</span></div>'
             . '<progress class="nu-wg-bar" value="' . $pct . '" max="100" aria-label="' . $R::esc($label) . '">' . $pct . '%</progress></div>';
    },
];
