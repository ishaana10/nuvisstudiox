# Dashboard & Widgets Redesign — Design

Status: awaiting approval · Date: 2026-11-20 · Repo: ishaana10/nuvisstudiox (v5.0.45)

## 1. Intent and why
Role dashboards (the widget section under the dashboard, `modules/widgets/`) look dated next to the rest of the app and load far too many widgets by default (96 on the "User" role, mostly duplicate "Available Forms" tiles). Only globeadmin can change anything, so there are no individual dashboards. Developers who want a new widget type must edit three places.

Goal: developers (globeadmin) design the dashboard each role starts with; every user can personalise layout, look and colours for themselves without losing later role updates; new widget types are one-file additions.

## 2. Goals / non-goals / anti-goals
Goals
- Modern widget cards that use the shared design tokens (light/dark, mobile, reduced motion).
- Role defaults managed by developers only; per-user overrides (order, size, hide/show, accent colour) plus per-user look settings (accent, density, card style).
- No more duplicate seeding; existing duplicates cleaned up once.
- Widget registry: one file per widget type, builder form and docs generated from it.
- Better widget capabilities: per-widget refresh, error isolation with retry, drill-down, thresholds, a few new types.

Non-goals
- No new JS grid library; no change to the admin/globeadmin KPI hero, activity chart or feed on `dashboard.php`.
- Users do not author SQL or HTML widgets.
- No cross-user sharing of personal layouts.

Anti-goals (a failure even if it "works")
- A user's customisation must never stop role changes reaching them (today personal rows replace the whole role set).
- Any user able to run arbitrary SQL through the widget API.
- Hardcoded colours/inline styles creeping back into widget markup.

## 3. Constraints
- PHP 8.1, MySQL 5.7+/MariaDB (no `ADD COLUMN IF NOT EXISTS`; use the INFORMATION_SCHEMA pattern already used in `widget_api.php`), shared hosting (A2), no build step.
- Keep the SPA module loading model (`NuApp.loadModule`) and existing tokens in `nubuilder-next.css`.
- Existing widget rows, `widget_icon` values (emoji or Font Awesome) and `{{user_id}}`/`{{role}}`/`{{today}}`… SQL placeholders keep working.
- `gateway.php` `handleDashboardEndpoint` reads `nu_dashboard_widgets` directly; its columns must stay compatible.
- `install.sql` and project exports bundle the schema, so schema changes go in `install.sql`, migration `009`, and the Database.php ensure-pattern.

## 3a. Approaches considered
- A. Restyle in place: smallest, but keeps the 600-line `wu_render()` switch and the replace-the-role-set personal model.
- **B. Registry + overrides (chosen by the user):** one file per type, shared role rows plus a per-user overrides table.
- C. Client-side grid rebuild: slickest but adds a dependency and rewrites every widget; highest risk.

## 4. Data model
`nu_dashboard_widgets` (existing) gains:
- `widget_key VARCHAR(64) NULL` — stable id for seeded widgets (e.g. `user.available_forms`).
- `widget_locked TINYINT(1) NOT NULL DEFAULT 0` — locked widgets cannot be hidden by users.
- `widget_refresh SMALLINT UNSIGNED NOT NULL DEFAULT 0` — seconds, 0 = off (replaces reading `refresh_interval` from config).
- `UNIQUE KEY uq_role_widget (widget_role, widget_key)` (NULL keys allowed, so developer-created rows are unaffected).

New `nu_dashboard_user_prefs`: `user_id`, `widget_id`, `hidden` (0/1), `position` (nullable), `width`, `height` (nullable = use role value), `accent` (nullable), `updated_at`; `UNIQUE (user_id, widget_id)`.

New `nu_dashboard_user_settings`: `user_id` PK, `settings` JSON `{accent, density: comfortable|compact, card_style: soft|flat|outlined}`, `updated_at`.

Accent values are validated server-side against `^#[0-9a-f]{6}$` or a named token from a fixed palette; never echoed unescaped.

Effective dashboard for a user = active role widgets for their role + that user's own personal rows (developers' personal widgets), then user prefs applied (hidden removed unless locked; position/size/accent overridden), sorted by user position falling back to role position.

## 5. Seeding fix and cleanup
- Seed rows in `install.sql` get `widget_key`; the unique key makes `INSERT IGNORE` actually ignore re-runs.
- Migration `db/migrations/009_dashboard_widgets_v2.sql` (+ matching ensure-block in `Database.php`, flagged per session like the others): add columns, backfill `widget_key` for seeded titles, delete exact duplicates among role rows (same role, type, title, config; keep the lowest `widget_id`), then add the unique key. Idempotent; takes no action when no duplicates exist.

## 6. Architecture
New / changed files
- `core/WidgetRegistry.php` — scans `modules/widgets/types/*.php`, returns type definitions; `NuWidgetRegistry::register()` lets plugins add types.
- `modules/widgets/types/<type>.php` — returns `['type','label','icon','group','defaults'=>[w,h,config],'fields'=>[…],'render'=>fn($widget,$cfg,$ctx)=>string]`. Field kinds: `sql, text, textarea, number, select, color, lines, html, url`. The builder form, the type picker and the docs table are generated from this. Existing 16 types ported 1:1, plus new `gauge` and `announcement` (safe text, no HTML).
- `modules/widgets/widgets.php` — thin: resolve widgets, output grid shell and skeletons, boot JS. Keeps `wu_chart_type`, `wu_type_accent`, `wu_accent` (moved to the registry helpers, shims left so `tests/Unit/WidgetTest.php` still passes).
- `modules/dashboard/widget_api.php` — adds `types`, `render` (returns one widget's HTML fragment), `prefs_save` (batch), `prefs_reset`, `settings_save`, `designer_load` (widgets for a chosen role). Legacy actions stay.
- `assets/css/nu-widgets.css`, `assets/js/nu-widgets.js` — replace the inline `<style>` in `widgets.php` and `modules/widgets/widgets.js`; linked once from `index.php` / `views/scripts.php`. Chart.js and Font Awesome stop being re-injected on every module load.

Rendering: each card loads its body via `action=render` in parallel with a skeleton, so one slow or failing SQL widget no longer breaks or delays the page, and per-widget refresh and retry come for free. Charts are drawn from a `data-nu-chart` JSON attribute by `nu-widgets.js`.

Security changes (part of this work)
- `run_sql` and `list_tables` become globeadmin-only; today any signed-in user can POST an arbitrary `SELECT` to `run_sql`.
- Role-row `add/update/remove/reorder` become globeadmin-only (the current ownership clause lets `admin` edit NULL-owner role rows).
- `render` only returns widgets visible to the requesting user and never accepts SQL from the client.
- Confirm the endpoint's CSRF handling matches `api/*` conventions and add the token if missing.

## 7. UI
Card: soft surface using `--bg-card`/`--border-color`, small tinted icon chip, plain title (no solid pill), quiet toolbar shown on hover/focus or in Customise mode, optional footer link. KPI: large value, delta pill, sparkline, threshold colour (`warn_at`/`error_at`). 12-column grid keeps the 1–4 width model; tablet 6 columns, phone 1 column. No hardcoded hex in markup; accent comes from a CSS variable per card.
- Customise mode (every user): drag handle plus Move up/down buttons (keyboard), size S/M/L/XL, hide, accent swatches; a side panel for hidden-widget tray, global accent, density, card style, and "Reset to role default".
- Role designer (globeadmin): role picker, live preview as that role, add/edit/remove/reorder, lock toggle, copy layout from another role, export/import role layout JSON. Replaces the "all roles stacked" view on the main dashboard.
- The widget builder becomes a proper `nu-modal`, uses a built-in SVG icon set (legacy emoji/FA values still render), no emoji in controls.

## 8. Testing
- PHPUnit: registry loading and contract validation, effective-widget resolution with prefs (pure function), accent validation, dedupe migration against the mock DB, API permission checks (non-admin cannot `run_sql` or edit role rows).
- Update `WidgetTest.php` for the moved helpers. Manual check: install.sql re-run twice → no new rows; 96-row case collapses to 4.

## 9. Left to the builder
Exact icon set, swatch palette values, skeleton and transition details, SQL for the dedupe on MySQL 5.7, minor API response shapes, doc updates (`docs/widgets.md`, README changelog).

## 10. Calls made by the author (not decided by the user)
1. "Developer" = the `globeadmin` role.
2. Users can reorder, resize, hide/show and recolour, and change look settings, but cannot add new widgets or write SQL/HTML.
3. Locked widgets exist so developers can pin must-have widgets.
4. Server-side rendering moves to per-widget fragment loading.
5. Two new widget types (`gauge`, `announcement`) added as examples of the registry.
6. Security tightening in §6 is included in scope.
