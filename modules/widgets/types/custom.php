<?php
declare(strict_types=1);

return [
    'type'  => 'custom',
    'label' => 'Custom HTML',
    'icon'  => 'code',
    'group' => 'Content',
    'defaults' => ['width' => 2, 'height' => 1, 'config' => ['html' => '']],
    'fields' => [
        ['key' => 'html', 'label' => 'HTML', 'kind' => 'html', 'required' => true, 'help' => 'Developer-authored markup, output as written.'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $html = (string)($cfg['html'] ?? '');
        return $html !== '' ? $html : NuWidgetRegistry::emptyHint('No content yet.');
    },
];
