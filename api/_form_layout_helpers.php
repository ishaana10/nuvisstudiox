<?php

function nu_flatten_layout_fields($layout) {
    if (is_string($layout)) {
        $decoded = json_decode($layout, true);
        $layout = is_array($decoded) ? $decoded : [];
    }

    if (isset($layout['items']) && is_array($layout['items'])) {
        $layout = $layout['items'];
    }

    $out = [];

    $walk = function($items) use (&$walk, &$out) {
        if (!is_array($items)) return;

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $type = strtolower(trim((string)($item['type'] ?? '')));

            // ── Tab container ──────────────────────────────────────────────
            if ($type === 'tab') {
                foreach (($item['tabs'] ?? []) as $tab) {
                    if (!is_array($tab)) continue;
                    foreach (($tab['rows'] ?? []) as $row) {
                        $rowType = strtolower(trim((string)($row['type'] ?? 'row')));
                        if ($rowType === 'group') {
                            $walk($row['rows'] ?? []);
                        } else {
                            $walk($row['fields'] ?? []);
                        }
                    }
                }
                continue;
            }

            // ── Section or Group (uses children[] at top level) ────────────
            if ($type === 'section' || $type === 'group') {
                $walk($item['children'] ?? []);
                foreach (($item['rows'] ?? []) as $row) {
                    $walk($row['fields'] ?? []);
                }
                continue;
            }

            // ── Plain row (uses fields[]) ──────────────────────────────────
            if ($type === 'row' || isset($item['fields'])) {
                $walk($item['fields'] ?? []);
                continue;
            }

            // ── Subform — skip, handled separately ────────────────────────
            if ($type === 'subform') {
                continue;
            }

            // ── Skip layout-only / non-data types ─────────────────────────
            if (in_array($type, ['html', 'content', 'button', 'fieldset', 'heading', 'divider', '_form_style'], true)) {
                continue;
            }

            // ── Leaf field — must have a name ──────────────────────────────
            if (!empty($item['name']) || !empty($item['fieldname'])) {
                $out[] = $item;
            }
        }
    };

    $walk($layout);
    return $out;
}

function nu_flatten_layout($layout) {
    return nu_flatten_layout_fields($layout);
}

function nu_flatten_subforms_from_layout($layout) {
    if (is_string($layout)) {
        $decoded = json_decode($layout, true);
        $layout = is_array($decoded) ? $decoded : [];
    }

    if (isset($layout['items']) && is_array($layout['items'])) {
        $layout = $layout['items'];
    }

    $out = [];

    $walk = function($items) use (&$walk, &$out) {
        if (!is_array($items)) return;

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $type = strtolower(trim((string)($item['type'] ?? '')));

            if ($type === 'subform') {
                $out[] = $item;
                continue;
            }

            if ($type === 'tab') {
                foreach (($item['tabs'] ?? []) as $tab) {
                    if (!is_array($tab)) continue;
                    foreach (($tab['rows'] ?? []) as $row) {
                        $rowType = strtolower(trim((string)($row['type'] ?? 'row')));
                        if ($rowType === 'group') {
                            $walk($row['rows'] ?? []);
                        } else {
                            $walk($row['fields'] ?? []);
                        }
                    }
                }
                continue;
            }

            if ($type === 'section' || $type === 'group') {
                $walk($item['children'] ?? []);
                foreach (($item['rows'] ?? []) as $row) {
                    $walk($row['fields'] ?? []);
                }
                continue;
            }

            if ($type === 'row' || isset($item['fields'])) {
                $walk($item['fields'] ?? []);
                continue;
            }
        }
    };

    $walk($layout);
    return $out;
}

/**
 * Parse form_style settings from form layout JSON or _form_style node.
 */
function nu_parse_form_style($layout) {
    if (is_string($layout)) {
        $decoded = json_decode($layout, true);
        $layout = is_array($decoded) ? $decoded : [];
    }
    $style = $layout['form_style'] ?? [];
    if (empty($style) && is_array($layout)) {
        foreach ($layout as $item) {
            if (is_array($item) && ($item['type'] ?? '') === '_form_style') {
                $style = $item;
                break;
            }
        }
    }
    if (!is_array($style)) $style = [];

    $density = in_array($style['density'] ?? '', ['compact', 'relaxed', 'comfortable'], true) ? $style['density'] : 'comfortable';
    $cardStyle = in_array($style['card_style'] ?? '', ['default', 'elevated', 'outlined', 'flush'], true) ? $style['card_style'] : 'default';
    $labelAlign = in_array($style['label_align'] ?? '', ['top', 'left', 'inline'], true) ? $style['label_align'] : 'top';
    $headerBg = !empty($style['header_bg']) ? (string)$style['header_bg'] : '';
    $accentColor = !empty($style['accent_color']) ? (string)$style['accent_color'] : '';
    $borderRadius = !empty($style['border_radius']) ? (string)$style['border_radius'] : '';

    return [
        'density' => $density,
        'card_style' => $cardStyle,
        'label_align' => $labelAlign,
        'header_bg' => $headerBg,
        'accent_color' => $accentColor,
        'border_radius' => $borderRadius,
    ];
}

/**
 * Build dynamic HTML class and style attribute string for styled edit forms.
 */
function nu_build_form_style_css(array $style): string {
    $classes = ['nu-generated-form', 'nu-form-styled'];
    if (!empty($style['density'])) $classes[] = 'nu-density-' . $style['density'];
    if (!empty($style['card_style'])) $classes[] = 'nu-card-' . $style['card_style'];
    if (!empty($style['label_align'])) $classes[] = 'nu-labels-' . $style['label_align'];

    $inline = ['font-size:13px'];
    if (!empty($style['header_bg'])) $inline[] = '--nu-form-header-bg: ' . $style['header_bg'];
    if (!empty($style['accent_color'])) $inline[] = '--nu-form-accent: ' . $style['accent_color'];
    if (!empty($style['border_radius'])) $inline[] = '--nu-form-radius: ' . $style['border_radius'];

    $classAttr = 'class="' . implode(' ', $classes) . '"';
    $styleAttr = 'style="' . implode('; ', $inline) . ';"';

    return $classAttr . ' ' . $styleAttr;
}
