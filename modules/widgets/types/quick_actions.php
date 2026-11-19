<?php
declare(strict_types=1);

return [
    'type'  => 'quick_actions',
    'label' => 'Quick Actions',
    'icon'  => 'bolt',
    'group' => 'Navigation',
    'defaults' => ['width' => 2, 'height' => 1, 'config' => ['items' => []]],
    'fields' => [
        ['key' => 'items', 'label' => 'Actions', 'kind' => 'lines', 'required' => true, 'help' => 'One per line: Label|form_code|icon  or  Label|https://url|icon'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $items = is_array($cfg['items'] ?? null) ? $cfg['items'] : [];
        $out = '';
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $attrs = $R::linkAttrs($item);
            if ($attrs === '') continue;
            $tag = str_starts_with($attrs, 'href=') ? 'a' : 'button type="button"';
            $end = $tag === 'a' ? 'a' : 'button';
            $ico = $R::iconHtml((string)($item['icon'] ?? 'fa-bolt'));
            $out .= '<' . $tag . ' class="nu-wg-tile" ' . $attrs . '>'
                  . ($ico !== '' ? '<span class="nu-wg-tile-ico">' . $ico . '</span>' : '')
                  . '<span class="nu-wg-tile-label">' . $R::esc((string)($item['label'] ?? '')) . '</span></' . $end . '>';
        }
        return $out === '' ? $R::emptyHint('No actions yet.') : '<div class="nu-wg-tiles">' . $out . '</div>';
    },
];
