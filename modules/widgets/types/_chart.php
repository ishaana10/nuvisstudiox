<?php
declare(strict_types=1);
/**
 * Shared helpers for chart-style widget types (file skipped by the registry scanner).
 * Charts emit a data attribute; assets/js/nu-widgets.js draws them with Chart.js
 * using the app's theme colours, so no colours or inline styles live in PHP.
 */

if (!function_exists('nu_wg_chart_markup')) {
    /** @param array<int,array> $rows rows with "label" and "value" columns */
    function nu_wg_chart_markup(string $kind, array $rows, string $title, bool $compact = false): string {
        $R = 'NuWidgetRegistry';
        if (!$rows) return $R::emptyHint('No data yet.');
        $type = match ($kind) {
            'chart_pie' => 'pie', 'chart_doughnut' => 'doughnut', 'chart_radar' => 'radar',
            'chart_line', 'chart_area', 'sparkline' => 'line', default => 'bar',
        };
        $json = json_encode([
            'type'    => $type,
            'labels'  => array_map('strval', array_column($rows, 'label')),
            'values'  => array_map(static fn($v) => is_numeric($v) ? $v + 0 : 0, array_column($rows, 'value')),
            'area'    => $kind === 'chart_area' || $kind === 'sparkline',
            'compact' => $compact,
            'label'   => $title,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return '<div class="nu-wg-chart' . ($compact ? ' is-compact' : '') . '" data-nu-chart="' . $R::esc($json)
             . '" role="img" aria-label="' . $R::esc($title) . '"><canvas></canvas></div>';
    }
}

if (!function_exists('nu_wg_chart_type_def')) {
    function nu_wg_chart_type_def(string $type, string $label, string $icon, int $width = 2, bool $compact = false): array {
        return [
            'type'  => $type,
            'label' => $label,
            'icon'  => $icon,
            'group' => 'Charts',
            'defaults' => ['width' => $width, 'height' => $compact ? 1 : 2, 'config' => ['sql' => '']],
            'fields' => [
                ['key' => 'sql', 'label' => 'SQL query', 'kind' => 'sql', 'required' => true, 'help' => 'Return "label" and "value" columns.'],
            ],
            'render' => static function (array $widget, array $cfg, array $ctx) use ($type, $compact): string {
                $sql = trim((string)($cfg['sql'] ?? ''));
                if ($sql === '') return NuWidgetRegistry::emptyHint('Not configured yet.');
                $rows = NuWidgetSql::run($ctx['db'], $sql, $ctx['userId'], $ctx['role']);
                return nu_wg_chart_markup($type, $rows, (string)($widget['widget_title'] ?? ''), $compact);
            },
        ];
    }
}
