<?php
declare(strict_types=1);

return [
    'type'  => 'table',
    'label' => 'Data Table',
    'icon'  => 'table',
    'group' => 'Data',
    'defaults' => ['width' => 4, 'height' => 2, 'config' => ['sql' => '']],
    'fields' => [
        ['key' => 'sql', 'label' => 'SQL query', 'kind' => 'sql', 'required' => true, 'help' => 'Column names become the table headings.'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $sql = trim((string)($cfg['sql'] ?? ''));
        if ($sql === '') return $R::emptyHint('Not configured yet.');
        $rows = NuWidgetSql::run($ctx['db'], $sql, $ctx['userId'], $ctx['role']);
        if (!$rows) return $R::emptyHint('No data.');
        $html = '<div class="nu-wg-table-wrap"><table class="nu-wg-table"><thead><tr>';
        foreach (array_keys($rows[0]) as $c) $html .= '<th scope="col">' . $R::esc(ucfirst((string)$c)) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $v) $html .= '<td>' . $R::esc((string)$v) . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    },
];
