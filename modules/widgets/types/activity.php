<?php
declare(strict_types=1);

return [
    'type'  => 'activity',
    'label' => 'Activity Feed',
    'icon'  => 'activity',
    'group' => 'Data',
    'defaults' => ['width' => 2, 'height' => 2, 'config' => ['sql' => '']],
    'fields' => [
        ['key' => 'sql', 'label' => 'SQL query', 'kind' => 'sql', 'required' => true, 'help' => 'Columns: time, user, text.'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $sql = trim((string)($cfg['sql'] ?? ''));
        $rows = $sql !== '' ? NuWidgetSql::run($ctx['db'], $sql, $ctx['userId'], $ctx['role']) : [];
        if (!$rows) return $R::emptyHint('No recent activity.');
        $out = '<ul class="nu-wg-feed">';
        foreach ($rows as $row) {
            $time = (string)($row['time'] ?? $row['created_at'] ?? '');
            $user = (string)($row['user'] ?? $row['actor_name'] ?? 'System');
            $text = (string)($row['text'] ?? $row['description'] ?? $row['action'] ?? '');
            $init = function_exists('mb_substr') ? mb_substr($user, 0, 1) : substr($user, 0, 1);
            $out .= '<li class="nu-wg-feed-item"><span class="nu-wg-avatar" aria-hidden="true">' . $R::esc(strtoupper($init)) . '</span>'
                  . '<div class="nu-wg-feed-body"><div class="nu-wg-feed-meta"><strong>' . $R::esc($user) . '</strong><span>' . $R::esc($time) . '</span></div>'
                  . '<div class="nu-wg-feed-text">' . $R::esc($text) . '</div></div></li>';
        }
        return $out . '</ul>';
    },
];
