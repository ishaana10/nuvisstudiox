# Dashboard Widgets

## Overview
Each role gets a dashboard that **only a developer (role `globeadmin`)** sets up. Every signed-in user can then make it their own: reorder, resize, hide, recolour, and choose a density and card style. Developers add new widget *types* by dropping one PHP file into `modules/widgets/types/`.

| Who | Can do |
|---|---|
| Developer (`globeadmin`) | Design the layout for any role (add, edit, remove, reorder, lock, copy from another role, export/import JSON); write widget SQL |
| Everyone signed in | Customise their own copy: order, size S/M/L/XL, hide/show, accent colour per card, default accent, density (comfortable/compact), card style (soft/flat/outlined), reset to role default |

Locked widgets (set by the developer) can't be hidden by users. Users never write SQL.

---

## Architecture & Key Files
- `core/WidgetRegistry.php` — discovers `modules/widgets/types/*.php` (files starting with `_` are helpers), validates them, renders fragments, built-in SVG icon set.
- `modules/widgets/types/*.php` — one file per widget type (the whole contract is below).
- `core/WidgetPolicy.php` — permission table; `core/WidgetPrefs.php` — per-user prefs validation/merge; `core/WidgetStore.php` — persistence; `core/WidgetSql.php` — single-SELECT runner with placeholders; `core/WidgetSchema.php` — self-healing schema, seeding keys and duplicate clean-up.
- `modules/widgets/widgets.php` — thin shell: renders card frames. Content is loaded per card as an HTML fragment.
- `modules/dashboard/widget_api.php` — JSON API (CSRF + policy checked).
- `assets/js/nu-widgets.js` (loader, charts, drill-down, customise mode), `assets/js/nu-widget-designer.js` (developer-only designer and builder, loaded on demand), `assets/css/nu-widgets.css`.

---

## Add a widget type in one file

Create `modules/widgets/types/countdown.php`:

```php
<?php
declare(strict_types=1);

return [
    'type'  => 'countdown',            // [a-z][a-z0-9_]*, stored in widget_type
    'label' => 'Days until',
    'icon'  => 'activity',             // a name from NuWidgetRegistry::svgIconNames()
    'group' => 'Metrics',              // Metrics | Charts | Data | Content | Navigation (or your own)
    'defaults' => ['width' => 1, 'height' => 1, 'config' => ['date' => '', 'label' => '']],
    'fields' => [                      // the builder form is generated from this
        ['key' => 'date',  'label' => 'Date', 'kind' => 'text', 'required' => true, 'help' => 'YYYY-MM-DD'],
        ['key' => 'label', 'label' => 'Label', 'kind' => 'text'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $ts = strtotime((string)($cfg['date'] ?? ''));
        if ($ts === false) return $R::emptyHint('Set a valid date.');
        $days = (int)ceil(($ts - time()) / 86400);
        return '<div class="nu-wg-stat"><div class="nu-wg-stat-main">'
             . '<div class="nu-wg-stat-value">' . $days . '</div>'
             . '<div class="nu-wg-stat-sub">' . $R::esc((string)($cfg['label'] ?? 'days to go')) . '</div></div></div>';
    },
];
```

That is all: it appears in the builder's type picker, renders through the API, and gets prefs, accent and loading/error states for free.

**Contract**
- `render(array $widget, array $cfg, array $ctx): string` — `$ctx` has `db`, `userId`, `role`. Return an HTML fragment. Escape everything with `NuWidgetRegistry::esc()`. Throwing is fine: the registry shows a retry card (details only for developers).
- No inline `style` attributes. Use the `nu-wg-*` classes in `assets/css/nu-widgets.css`; charts emit `data-nu-chart` JSON (see `_chart.php`) and the runtime draws them; progress uses `<progress>`.
- Field kinds: `sql`, `text`, `textarea`, `number`, `select` (with `options`), `color`, `lines`, `html`, `url`.
- Helpers: `esc`, `safeUrl`, `iconHtml`, `emptyHint`, `errorFragment`, `firstValue`, `linkAttrs` (drill-down / safe link attributes).
- Test it: `NuWidgetRegistry::validate($def)` returns problems; `tests/Unit/WidgetTypesTest.php` shows how types are exercised.

## Built-in types

| Type | Purpose |
|---|---|
| `stat` | KPI value; optional `warn_at`/`error_at` thresholds and drill-down |
| `metric_trend` | KPI with change indicator and sparkline |
| `sparkline`, `chart_bar`, `chart_line`, `chart_area`, `chart_pie`, `chart_doughnut`, `chart_radar` | Charts from `label`/`value` rows |
| `table`, `list`, `activity` | Data, link lists, audit feed |
| `quick_actions` | Tile grid: `Label|form_code|icon` or `Label|https://url|icon` |
| `progress`, `gauge` | Completion bar and half-circle gauge (`done`/`total`) |
| `announcement` | Plain-text note (info/success/warning) |
| `iframe` | Embedded page (the site must allow framing and CSP `frame-src` must allow it) |
| `custom` | Developer-authored HTML |

## SQL placeholders
`{{user_id}}`, `{{role}}`, `{{today}}`, `{{this_month}}`, `{{this_year}}`. Queries must be a single `SELECT`.

---

## Data model
- `nu_dashboard_widgets` — role widgets (`widget_user_id` NULL, `widget_role`) and personal rows. New columns: `widget_key` (stable seed key; `UNIQUE (widget_role, widget_key)` stops re-seeding duplicates), `widget_locked`, `widget_refresh` (seconds, 0 = off).
- `nu_dashboard_user_prefs` — per user and widget: `hidden`, `position`, `width`, `height`, `accent`.
- `nu_dashboard_user_settings` — per user JSON: `accent`, `density`, `card_style`.

The effective dashboard is the role's widgets plus the user's own rows, with prefs applied. The schema upgrades itself on first use (`NuWidgetSchema::ensure`) and also ships as `db/migrations/009_dashboard_widgets_v2.sql`; the upgrade collapses duplicate seeded rows.

## API (`modules/dashboard/widget_api.php?action=…`)
All POSTs need the `X-CSRF-Token` header.

| Action | Who | Purpose |
|---|---|---|
| `list`, `render` (`id`) | signed in | effective widgets; HTML fragment for one widget (only ones visible to the caller; developers may preview any role widget) |
| `prefs_save`, `prefs_reset`, `settings_save` | signed in | the caller's own layout and look |
| `types`, `designer_load` (`role`), `add`, `update`, `remove`, `reorder` | developer | role layout management (`add`/`update` accept `target_role`, `locked`, `refresh`) |
| `run_sql`, `list_tables` | developer | builder helpers |

Permissions live in one table, `NuWidgetPolicy::can($action, $role)`.
