<?php
declare(strict_types=1);

return [
    'type'  => 'list',
    'label' => 'Quick Links',
    'icon'  => 'link',
    'group' => 'Navigation',
    'defaults' => ['width' => 2, 'height' => 1, 'config' => ['items' => []]],
    'fields' => [
        ['key' => 'items', 'label' => 'Links', 'kind' => 'lines', 'required' => true, 'help' => 'One per line: Label|form_code  or  Label|https://url'],
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
            $out .= '<' . $tag . ' class="nu-wg-listitem" ' . $attrs . '><span>' . $R::esc((string)($item['label'] ?? '')) . '</span>'
                  . '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></' . $end . '>';
        }
        return $out === '' ? $R::emptyHint('No links yet.') : '<div class="nu-wg-list">' . $out . '</div>';
    },
];
