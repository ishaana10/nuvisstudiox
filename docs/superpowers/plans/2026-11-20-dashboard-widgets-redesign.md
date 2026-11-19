# Dashboard & Widgets Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modern role dashboards with developer-managed role defaults, per-user layout/look overrides, no duplicate seeding, and a one-file-per-type widget registry.

**Architecture:** Role widget rows stay shared; a per-user prefs table overrides order/size/visibility/accent and a settings table holds look preferences. Widget types become registry files rendered as fragments loaded in parallel by `nu-widgets.js`. Authorisation and prefs resolution live in small pure PHP classes so they are unit-testable on the SQLite test DB.

**Tech Stack:** PHP 8.1+ (tests on 8.3), MySQL 5.7+/MariaDB (tests on SQLite via `tests/bootstrap.php`), vanilla JS, Chart.js 4.4.3 (already used), PHPUnit 10.5 (`composer install` first, no `vendor/` yet).

**Spec:** `docs/superpowers/specs/2026-11-20-dashboard-widgets-redesign-design.md`

## Global Constraints

- No `ADD COLUMN IF NOT EXISTS` (MySQL 5.7): check `INFORMATION_SCHEMA.COLUMNS` first, as `modules/dashboard/widget_api.php` already does.
- Developer = role `globeadmin`. Only globeadmin may add/update/remove/reorder role rows, call `run_sql`, `list_tables`, `designer_load`, `types`.
- Accent values must match `^#[0-9a-f]{6}$` (lowercase) or a named token from `NuWidgetPrefs::ACCENT_TOKENS`; anything else is stored as NULL.
- Settings: `density` ∈ `comfortable|compact`; `card_style` ∈ `soft|flat|outlined`; defaults `comfortable`, `soft`.
- Widget width 1–4 (×3 of 12 columns), height 1–3; tablet 6 columns, phone 1 column.
- No hardcoded hex colours or inline `style=` in new widget markup; colours come from tokens in `nubuilder-next.css` and a per-card `--nu-wg-accent` variable.
- SQL placeholders `{{user_id}} {{role}} {{today}} {{this_month}} {{this_year}}` and legacy emoji/Font Awesome `widget_icon` values keep working.
- `api/gateway.php` `handleDashboardEndpoint` reads `nu_dashboard_widgets` columns directly: do not rename or drop existing columns.
- Every write action in `widget_api.php` verifies `X-CSRF-Token` (`$auth->verifyCsrf`), as `api/upload.php` does; JS sends `window.nuCsrfToken`.
- Commit after each task; commit messages end with the attribution lines from the session's system reminder.

## Review Focus

1. Widget with an unknown type or broken SQL: its card shows an error state with Retry; the page and other widgets are unaffected.
2. Malicious accent (`red;}</style><script>`): rejected server-side, never reaches markup.
3. Locked widget hidden through a crafted `prefs_save` call: server ignores the `hidden` flag.
4. Prefs pointing at a deleted/deactivated widget, or a role with zero widgets: orphan prefs ignored, friendly empty state, no crash.
5. Running the schema ensure/migration twice, or with duplicates whose configs differ: second run is a no-op, only exact duplicates are removed.

---

## File Structure

| File | Responsibility |
|---|---|
| `core/WidgetSchema.php` (new) | `ensure(NuDatabase)`: columns, tables, dedupe, unique key; idempotent |
| `core/WidgetPrefs.php` (new) | Pure: accent/settings validation, `resolve()` effective widgets |
| `core/WidgetPolicy.php` (new) | Pure: `can(action, role)` permission table |
| `core/WidgetSql.php` (new) | `run()`: placeholder substitution, SELECT-only execution |
| `core/WidgetRegistry.php` (new) | Scan/register/get types, `renderWidget()` with error isolation |
| `modules/widgets/types/*.php` (new, 18) | One type definition each |
| `modules/dashboard/widget_api.php` | Policy + CSRF + new actions |
| `modules/widgets/widgets.php` | Thin shell: resolve, grid markup, boot data |
| `assets/css/nu-widgets.css`, `assets/js/nu-widgets.js` (new) | Cards, grid, customise mode, designer, builder |
| `modules/widgets/widgets.js` | Deleted at the end |
| `install.sql`, `db/migrations/009_dashboard_widgets_v2.sql` | Schema + keyed seeds |
| `tests/Unit/Widget*Test.php` | Per-class tests |

---

### Task 1: Schema, keyed seeds, duplicate cleanup

**Files:**
- Create: `core/WidgetSchema.php`, `db/migrations/009_dashboard_widgets_v2.sql`, `tests/Unit/WidgetSchemaTest.php`
- Modify: `install.sql:418-435` (columns, unique key, new tables), `install.sql:853-886` (add `widget_key` to seeds), `core/Database.php` (call `NuWidgetSchema::ensure` using the session-flag pattern at lines ~110/199)

**Interfaces:**
- Produces: `NuWidgetSchema::ensure(NuDatabase $db): void`; `NuWidgetSchema::dedupeRoleWidgets(NuDatabase $db): int` (rows removed). Columns `widget_key VARCHAR(64) NULL`, `widget_locked TINYINT(1) NOT NULL DEFAULT 0`, `widget_refresh SMALLINT UNSIGNED NOT NULL DEFAULT 0`; unique `uq_role_widget (widget_role, widget_key)`; tables `nu_dashboard_user_prefs(pref_id, user_id, widget_id, hidden, position NULL, width NULL, height NULL, accent NULL, updated_at, UNIQUE(user_id,widget_id))`, `nu_dashboard_user_settings(user_id PK, settings JSON/TEXT, updated_at)`.

- [ ] **Step 1: Write failing tests** in `WidgetSchemaTest.php` on the SQLite DB: `testDedupeKeepsLowestIdOfExactDuplicates` (4 identical role rows → 1 left, returns 3), `testDedupeKeepsRowsWithDifferentConfig`, `testEnsureIsIdempotent` (call twice, row count and column set unchanged), `testEnsureBackfillsWidgetKeyForSeededTitles` (`user` + `Available Forms` → `user.available_forms`).
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter WidgetSchemaTest`; expect FAIL (class not found).
- [ ] **Step 3: Implement `NuWidgetSchema`.** Dedupe groups by (role, type, title, config) over rows with `widget_user_id IS NULL`, keeps `MIN(widget_id)`; use a derived-table subquery so it also works on MySQL (error 1093). Add the unique key last, after dedupe and backfill. Keep driver-specific DDL behind a `$db` driver check (SQLite for tests, MySQL otherwise).
- [ ] **Step 4:** Mirror the same changes by hand in `install.sql` (seed rows get keys `user.available_forms`, `user.available_reports`, `user.quick_links`, `user.my_recent_activity`, `manager.total_users`, `manager.active_forms`, `manager.activity_this_week`, `manager.actions_breakdown`) and in `009_dashboard_widgets_v2.sql` (MySQL-only, guarded by INFORMATION_SCHEMA checks).
- [ ] **Step 5:** Run `vendor/bin/phpunit --filter WidgetSchemaTest`; expect PASS. Run `php -l core/WidgetSchema.php core/Database.php`.
- [ ] **Step 6: Commit** `feat(widgets): keyed seeds, unique key and duplicate cleanup`.

---

### Task 2: Prefs resolution and validation (pure)

**Files:**
- Create: `core/WidgetPrefs.php`, `tests/Unit/WidgetPrefsTest.php`

**Interfaces:**
- Produces:
  - `NuWidgetPrefs::ACCENT_TOKENS` (const array: `teal, blue, violet, rose, amber, green, slate`)
  - `NuWidgetPrefs::validAccent(?string $v): ?string`
  - `NuWidgetPrefs::normalizeSettings(array $in): array` → `['accent'=>?string,'density'=>string,'card_style'=>string]`
  - `NuWidgetPrefs::resolve(array $widgets, array $prefsByWidgetId): array` — widgets are DB rows; returns `['visible'=>array,'hidden'=>array]` with effective `widget_width`, `widget_height`, `accent`, sorted by user position else `widget_position`.
  - `NuWidgetPrefs::sanitizeSaveItems(array $items, array $widgetsById): array` — drops unknown widget ids, nulls invalid accents, clamps sizes, ignores `hidden` for locked widgets; used by the API in Task 6.

- [ ] **Step 1: Write failing tests:** `testValidAccentAcceptsHexAndTokens`, `testValidAccentRejectsInjection` (`"red;}</style><script>"`, `"#GGGGGG"`, `"#ABCDEF"` → null because lowercase only), `testNormalizeSettingsDefaults`, `testNormalizeSettingsRejectsUnknownDensity`, `testResolveAppliesUserOrderSizeAndAccent`, `testResolveCannotHideLockedWidget`, `testResolveIgnoresOrphanPrefs` (pref for id not in widgets), `testResolveClampsWidthAndHeight` (width 9 → 4, 0 → 1), `testSaveItemsIgnoreHiddenForLockedWidget`, `testSaveItemsDropUnknownWidgetIds`, `testSaveItemsInvalidAccentBecomesNull`.
- [ ] **Step 2:** Run the test file; expect FAIL.
- [ ] **Step 3:** Implement the class as pure static methods (no DB).
- [ ] **Step 4:** Run; expect PASS.
- [ ] **Step 5: Commit** `feat(widgets): prefs validation and resolution`.

---

### Task 3: Policy and SQL runner

**Files:**
- Create: `core/WidgetPolicy.php`, `core/WidgetSql.php`, `tests/Unit/WidgetPolicyTest.php`, `tests/Unit/WidgetSqlTest.php`

**Interfaces:**
- Produces: `NuWidgetPolicy::can(string $action, string $role): bool` — actions `run_sql, list_tables, designer_load, types, role_add, role_update, role_remove, role_reorder` → globeadmin only; `render, prefs_save, prefs_reset, settings_save, list` → any authenticated role (including empty string → false for all).
- Produces: `NuWidgetSql::run(NuDatabase $db, string $sql, int $userId, string $role): array` (empty array if not a single `SELECT`; throws `RuntimeException` with the DB message on query failure); `NuWidgetSql::substitute(string $sql, int $userId, string $role): string`.

- [ ] **Step 1: Write failing tests:** `testAdminCannotEditRoleRows` (role `admin`, action `role_update` → false), `testUserCannotRunSql`, `testGlobeadminCanDoEverything`, `testEmptyRoleDeniedAll`; `testSubstituteReplacesAllPlaceholders`, `testRunRejectsNonSelect` (`DELETE …`, `SELECT 1; DROP TABLE x` → `[]`), `testRunRejectsMultipleStatements`.
- [ ] **Step 2:** Run; expect FAIL.
- [ ] **Step 3:** Implement both classes; move the substitution map currently duplicated in `widgets.php` (`wu_run_sql`) and `widget_api.php` (`wu_safe_sql`) into `substitute`.
- [ ] **Step 4:** Run; expect PASS.
- [ ] **Step 5: Commit** `feat(widgets): policy and shared SQL runner`.

---

### Task 4: Registry and first types (stat, list, custom, announcement)

**Files:**
- Create: `core/WidgetRegistry.php`, `modules/widgets/types/{stat,list,custom,announcement}.php`, `tests/Unit/WidgetRegistryTest.php`

**Interfaces:**
- Consumes: `NuWidgetSql::run`.
- Produces:
  - `NuWidgetRegistry::all(): array<string,array>` (keyed by type; scans `modules/widgets/types/*.php`, each file returns the definition array)
  - `NuWidgetRegistry::get(string $type): ?array`
  - `NuWidgetRegistry::register(array $def): void` (for plugins; validates contract)
  - `NuWidgetRegistry::validate(array $def): string[]` (list of problems, empty = valid)
  - `NuWidgetRegistry::renderWidget(array $widget, NuDatabase $db, int $userId, string $role): array` → `['ok'=>bool,'html'=>string]`; never throws.
  - Definition contract: `type, label, icon, group, defaults(['width'=>int,'height'=>int,'config'=>array]), fields[] (key,label,kind,help?,required?,options?), render: callable(array $widget, array $cfg, array $ctx): string` where `$ctx = ['db'=>NuDatabase,'userId'=>int,'role'=>string]`. Field kinds: `sql, text, textarea, number, select, color, lines, html, url`.

- [ ] **Step 1: Write failing tests:** `testAllLoadsEveryTypeFileAndEachValidates`, `testGetUnknownTypeReturnsNull`, `testRenderUnknownTypeGivesErrorFragment` (ok=false, html contains `nu-wg-error`), `testRenderBrokenSqlGivesErrorFragmentNotException`, `testRenderEscapesTitleAndValues` (`<b>` in subtitle is escaped), `testRegisterRejectsInvalidDefinition`, `testStatRendersFirstColumnWhenNoValueColumn`, `testAnnouncementStripsHtml`.
- [ ] **Step 2:** Run; expect FAIL.
- [ ] **Step 3:** Implement the registry and the four types by porting behaviour from `wu_render()` cases `stat`, `list`, `custom` (`modules/widgets/widgets.php:124-160, 388-420`); `stat` gains optional `warn_at`/`error_at` fields that add `is-warn`/`is-error` classes; `announcement` has `text` + `tone` (`info|success|warning`). Markup uses `nu-wg-*` classes only (defined in Task 7); no inline styles.
- [ ] **Step 4:** Run; expect PASS.
- [ ] **Step 5: Commit** `feat(widgets): registry with stat, list, custom, announcement`.

---

### Task 5: Port remaining types (charts, trend, table, activity, progress, iframe, quick_actions) + gauge

**Files:**
- Create: `modules/widgets/types/{chart_bar,chart_line,chart_area,chart_pie,chart_doughnut,chart_radar,sparkline,metric_trend,table,activity,progress,quick_actions,iframe,gauge}.php`, a shared `modules/widgets/types/_chart.php` helper (underscore files are skipped by the scanner), `tests/Unit/WidgetTypesTest.php`
- Modify: `tests/Unit/WidgetTest.php` (keep passing; helpers `wu_chart_type`, `wu_type_accent`, `wu_accent` move into `core/WidgetRegistry.php` as global functions with the same names and outputs)

**Interfaces:**
- Consumes: Task 4 contract. Chart types render `<canvas>` inside `<div class="nu-wg-chart" data-nu-chart='{json}'>` where JSON is `{type, labels[], values[], area:bool}`; `nu-widgets.js` draws it.

- [ ] **Step 1: Write failing tests:** one data-provider test `testEachTypeRendersWithMinimalConfig` over all 18 types (against the mock DB), `testChartTypeEmitsDataAttributeJson`, `testProgressComputesPercent` (done 3, total 4 → 75), `testGaugeClampsTo100`, `testIframeRejectsNonHttpUrl` (`javascript:` → error fragment), `testTableEscapesCells`, `testActivityMapsTimeUserText`.
- [ ] **Step 2:** Run; expect FAIL for the new types.
- [ ] **Step 3:** Port each case from `wu_render()` (`widgets.php:162-418`), same SQL column contracts as `docs/widgets.md` (`label`/`value`, `value`/`prev_value`, `done`/`total`, `time`/`user`/`text`). `quick_actions`/`list` lines keep the `Label|form_code|fa-icon` format. `gauge` takes `done`/`total` like `progress` and renders a semicircle SVG.
- [ ] **Step 4:** Run `vendor/bin/phpunit --filter "Widget"`; expect PASS.
- [ ] **Step 5: Commit** `feat(widgets): port all widget types to the registry, add gauge`.

---

### Task 6: API (policy, CSRF, prefs, render, designer)

**Files:**
- Modify: `modules/dashboard/widget_api.php`
- Create: `tests/Unit/WidgetApiLogicTest.php` (logic is in classes; the API file stays thin glue)

**Interfaces:**
- Consumes: `NuWidgetPolicy::can`, `NuWidgetPrefs::{resolve,validAccent,normalizeSettings}`, `NuWidgetSql::run`, `NuWidgetRegistry::renderWidget/all`, `NuWidgetSchema::ensure`.
- Produces (all JSON, `{ok:true,…}` or `{error:string}` with HTTP 403 for policy/CSRF failures):
  - `GET list` → `{widgets:[row+effective fields], hidden:[…], settings:{…}}`
  - `GET render&id=N` → `{ok, html}` (only for widgets visible to the caller)
  - `POST prefs_save` body `{items:[{id,hidden?,position?,width?,height?,accent?}]}`
  - `POST prefs_reset`; `POST settings_save` body `{accent,density,card_style}`
  - `GET types` → registry manifest (label, icon, group, defaults, fields) — globeadmin
  - `GET designer_load&role=code` → role widgets + roles list — globeadmin
  - `POST add|update|remove|reorder` (role rows) — globeadmin; `add` accepts `widget_key`, `locked`, `refresh`
  - `POST run_sql`, `GET list_tables` — globeadmin only
  - Legacy `reset` now means `prefs_reset` for the caller.

- [ ] **Step 1: Write failing tests** in `WidgetApiLogicTest.php` for the glue that is not pure: `testPrefsRoundTripOnSqlite` (save prefs for user, reload through `NuWidgetPrefs::resolve`, order/size/accent applied), `testPrefsResetDeletesOnlyThatUsersRows`, `testSettingsSaveStoresNormalizedJson`.
- [ ] **Step 2:** Run; expect FAIL.
- [ ] **Step 3:** Rewrite `widget_api.php` action dispatch to: ensure schema once per session, authorise via `NuWidgetPolicy::can($action,$role)`, check CSRF for POSTs (`X-CSRF-Token`), then perform the action. Role-row writes drop the `OR widget_user_id IS NULL` ownership trick; globeadmin may write NULL-owner rows, everyone else only their own personal rows via prefs.
- [ ] **Step 4:** Run `vendor/bin/phpunit --filter Widget` and `php -l modules/dashboard/widget_api.php`; expect PASS.
- [ ] **Step 5: Commit** `feat(widgets): policy-checked API with prefs, render and designer actions`.

---

### Task 7: Card styles and dashboard shell

**Files:**
- Create: `assets/css/nu-widgets.css`, `assets/js/nu-widgets.js` (loader part: fetch fragments, skeleton, error + Retry, per-widget refresh from `data-refresh`, chart drawing from `data-nu-chart`, auto-pause when tab hidden)
- Modify: `modules/widgets/widgets.php` (replace inline `<style>`, per-load Chart.js/Font Awesome injection and `wu_render` call with the grid shell), `index.php` (link `nu-widgets.css`; load Chart.js once), `views/scripts.php` (load `nu-widgets.js`; keep `modules/widgets/widgets.js` until Task 9)

**Interfaces:**
- Consumes: API `list`, `render`.
- Produces: markup contract `div#nuWidgetGrid.nu-wg-grid[data-density][data-card-style]` containing `article.nu-wg-card[data-widget-id][data-refresh][data-locked][style="--nu-wg-accent:…;--nu-wg-span:N;--nu-wg-rows:N"]` with `.nu-wg-head`, `.nu-wg-body`, `.nu-wg-tools`. Global `window.NuWidgets = {init(root), refresh(id), customise(on)}`.

- [ ] **Step 1:** Write `nu-widgets.css` on tokens: `.nu-wg-card` (surface, border, radius, hover only on pointer), icon chip, KPI/delta/threshold classes, skeleton, `.nu-wg-error`, empty state, `[data-density=compact]`, `[data-card-style=flat|outlined]`, dark mode via existing tokens, `prefers-reduced-motion`, 12/6/1 column breakpoints.
- [ ] **Step 2:** Rewrite `widgets.php` to resolve widgets with `NuWidgetPrefs::resolve`, output card shells with skeletons and the empty state for zero widgets ("No widgets for your role yet" + developer hint for globeadmin). Keep the file includable from `dashboard.php`/`dashboard_user.php`.
- [ ] **Step 3:** Implement the loader in `nu-widgets.js` (parallel fetch of `render`, isolated failures, Retry button, `NuWidgets.refresh`).
- [ ] **Step 4: Verify:** `php -l` on changed PHP; with the app running (Task 9 describes setup) load Dashboard as `user` role: cards render, a deliberately broken SQL widget shows the error state with Retry, resizing to 375px shows one column, dark theme has no unreadable text.
- [ ] **Step 5: Commit** `feat(widgets): modern card styles and fragment-loading dashboard shell`.

---

### Task 8: Customise mode and look settings (every user)

**Files:**
- Modify: `assets/js/nu-widgets.js`, `assets/css/nu-widgets.css`, `modules/widgets/widgets.php` (toolbar: Customise button for all users)

**Interfaces:**
- Consumes: API `prefs_save`, `prefs_reset`, `settings_save`, `list`.
- Produces: `NuWidgets.customise(true|false)`; side panel `#nuWgPanel` with hidden-widget tray, accent swatches (`NuWidgetPrefs::ACCENT_TOKENS` + custom colour input), density and card-style toggles, "Reset to role default" using the app's existing confirm pattern (`nu-modal`, not `confirm()`).

- [ ] **Step 1:** Implement drag handle reorder plus Move up/Move down buttons (keyboard, `aria-live` announcement), size S/M/L/XL buttons mapped to widths 1–4, hide/show; debounce 600 ms then `prefs_save` with the full changed set; show an inline "Saved"/"Couldn't save, retry" status.
- [ ] **Step 2:** Locked widgets show a lock icon and no hide button. Per-card accent swatches write `accent`; global panel writes `settings_save` and updates `data-density`/`data-card-style` immediately.
- [ ] **Step 3: Verify manually as a `user` role account:** reorder, resize, hide, recolour, switch density; reload and everything persists; log in as globeadmin, add a role widget, and confirm the user receives it after reload while keeping their order; Reset restores the role default.
- [ ] **Step 4: Commit** `feat(widgets): per-user customise mode and look settings`.

---

### Task 9: Role designer, generated builder, integration, docs, cleanup

**Files:**
- Modify: `assets/js/nu-widgets.js`, `assets/css/nu-widgets.css`, `modules/dashboard/dashboard.php` / `dashboard_user.php` (remove the all-roles stacked view; add "Design role layout" button for globeadmin only), `docs/widgets.md`, `README.md` (changelog entry under Unreleased), `tests/Unit/WidgetTest.php`
- Delete: `modules/widgets/widgets.js`

**Interfaces:**
- Consumes: API `types`, `designer_load`, `add`, `update`, `remove`, `reorder`, `run_sql`.
- Produces: designer view `#nuWgDesigner` (role picker, live preview rendered as that role via `render`, add/edit/remove/reorder, lock toggle, copy-layout-from-role, export/import role layout JSON); builder `nu-modal` generated from registry `fields`.

- [ ] **Step 1:** Implement the designer and the field-driven builder; the type picker groups by registry `group`; icon input uses a small built-in SVG icon set, legacy emoji/FA values still display.
- [ ] **Step 2:** Update `docs/widgets.md` to describe the registry contract with a copy-pasteable example type file (the "add a widget in one file" recipe), prefs tables, API actions and the permission matrix; add the README changelog entry.
- [ ] **Step 3:** Delete `modules/widgets/widgets.js` and its `<script>` tag; grep for `nuDash.` and fix any caller.
- [ ] **Step 4: Full verification:** `composer install && vendor/bin/phpunit` all suites green; `php -l` on every changed PHP file; re-run `install.sql` twice on a scratch MySQL/MariaDB and confirm widget counts do not grow; load an existing database containing 96 duplicates and confirm it collapses to 4; walk the manual checklist (user, manager, globeadmin; light/dark; 375px).
- [ ] **Step 5: Commit** `feat(widgets): role designer, field-driven builder, docs; remove legacy widgets.js`.

---

## Self-Review

- **Spec coverage:** §4 data model → T1; §5 seeding/cleanup → T1; §6 registry/API/security → T3–T6; §7 UI → T7–T9; §8 testing → each task; non-goals respected (no grid library, no user SQL).
- **Type consistency:** `sanitizeSaveItems` is added to `NuWidgetPrefs` (Task 2 class) and used in Task 6; `NuWidgetRegistry::renderWidget` signature is identical in Tasks 4, 5 and 6.
- **Proportion:** nine tasks, signatures and assertions only; implementers write bodies.
