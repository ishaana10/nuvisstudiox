<?php
declare(strict_types=1);

return [
    'type'  => 'iframe',
    'label' => 'External iFrame',
    'icon'  => 'globe',
    'group' => 'Content',
    'defaults' => ['width' => 4, 'height' => 2, 'config' => ['url' => '', 'height' => 280]],
    'fields' => [
        ['key' => 'url', 'label' => 'Page URL', 'kind' => 'url', 'required' => true, 'help' => 'http(s) address. The site must allow embedding and your CSP frame-src must allow it.'],
        ['key' => 'height', 'label' => 'Height (px)', 'kind' => 'number'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $url = $R::safeUrl((string)($cfg['url'] ?? ''));
        if ($url === '' || !preg_match('#^(https?://|/)#i', $url)) return $R::emptyHint('Add a valid page URL.');
        $h = max(120, min(1200, (int)($cfg['height'] ?? 280)));
        return '<iframe class="nu-wg-frame" src="' . $R::esc($url) . '" height="' . $h . '" loading="lazy" referrerpolicy="no-referrer"'
             . ' sandbox="allow-scripts allow-same-origin allow-forms allow-popups" title="' . $R::esc((string)($widget['widget_title'] ?? 'Embedded page')) . '"></iframe>';
    },
];
