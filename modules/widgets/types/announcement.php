<?php
declare(strict_types=1);

return [
    'type'  => 'announcement',
    'label' => 'Announcement',
    'icon'  => 'megaphone',
    'group' => 'Content',
    'defaults' => ['width' => 4, 'height' => 1, 'config' => ['text' => '', 'tone' => 'info']],
    'fields' => [
        ['key' => 'text', 'label' => 'Message', 'kind' => 'textarea', 'required' => true, 'help' => 'Plain text only; line breaks are kept.'],
        ['key' => 'tone', 'label' => 'Tone', 'kind' => 'select', 'options' => ['info', 'success', 'warning']],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $text = trim((string)($cfg['text'] ?? ''));
        if ($text === '') return $R::emptyHint('No message yet.');
        $want = (string)($cfg['tone'] ?? 'info');
        $tone = in_array($want, ['info', 'success', 'warning'], true) ? $want : 'info';
        return '<div class="nu-wg-note tone-' . $tone . '">' . nl2br($R::esc($text), false) . '</div>';
    },
];
