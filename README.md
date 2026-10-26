# Nuvis StudioX

A premium, modern web development application for **Nuvis Technologies** (`web.nuvistechnologies.com.fj`) — PHP/MySQL, fully self-hosted low-code platform.

>  **Host:** A2Hosting (cPanel) · **Stack:** PHP8.1+, MySQL 5.7+, Apache

---

## Version Changelog

### Unreleased — Builders
- **Query Builder (`modules/queries/`):** schema browser sidebar (search tables and columns, click to insert, one-click `SELECT`), SQL editor with highlighting, schema-aware autocomplete and a formatter, a Visual builder that hands off to SQL, **Run unsaved SQL** (`Ctrl/Cmd+Enter`) with a result grid (filter, sort, copy, CSV, timing), `:placeholder` parameter detection with one-click "Add parameter", test values, unsaved-changes guard, list search and **Duplicate**.
- **Report Builder (`modules/reports/`):** same editor and schema browser, shared result grid for preview and run, **Detect columns from SQL**, real **Chart.js** charts (bar / line / pie / doughnut with label and value pickers), list search and **Duplicate**. PDF templates, email and the pivot view are unchanged.
- **API:** `queries.php` gains `preview`, `get_schema`, `duplicate`; `report.php` gains `duplicate`, a preview row limit and timings. `NuQueryExecutor::runSql()` binds only the placeholders present, binds blank defined parameters as `NULL` (so `(:p IS NULL OR col = :p)` works) and no longer HTML-escapes text values.
- **Security hardening:** report `run` is now project-scoped and whitelists filter field names and operators.
- **Theme:** legacy colour tokens (`--color-surface`, `--color-border`, …) are aliased to the app theme globally (`nu-builders.css`).

### Unreleased — Dashboard
- **Redesigned dashboards (`modules/dashboard/`, `nu-dashboard.css`, `nu-dashboard.js`):** greeting hero with date, role and project; clickable KPI cards with a 7-day sparkline and trend vs yesterday; quick-action tiles; last-7-days activity chart; and a recent-activity feed with avatars, relative times and Created / Updated / Deleted / Logins filters. First-run callout when a project has no forms. Responsive down to phone width.
- **Refresh controls:** Refresh button plus an opt-in 1-minute auto-refresh that pauses while the tab is hidden, the widget layout is being edited, or a dialog is open.
- **Widget section:** legacy colour tokens used by `widgets.php` are now aliased to the app theme, fixing its light-on-dark panels in dark mode.

### Unreleased — Forms
- **Shared form shell (`nu-form-shell.js`, `nu-form-shell.css`):** add / edit / view-only / preview forms now share one chrome in modal, inline and full-page modes (replacing three inline-styled modal builders). Mode badge, sticky action bar, unsaved-changes indicator and discard confirmation (Cancel / ✕ / Esc / backdrop / leaving the page), `Ctrl/Cmd+S` to save, first-field autofocus, bottom-sheet layout on phones.
- **Edit form:** save button shows a busy state and re-enables on failure; required fields are marked with `*`; inline validation messages (accessible, cleared as you type) replace the browser bubble; view-only records get an **Edit** shortcut.
- **Preview form:** "nothing is saved until you press Save" banner, Desktop / Tablet / Mobile device switcher that emulates the responsive column fallbacks, and S / M / L dialog sizes.

### Unreleased
- **Browse Table redesign (`nu-browse-table.js`, `nu-browse.css`):** replaces the inline-styled grid with a namespaced component — sticky header, server-side search/sort/pagination, per-column filters, column show/hide/reorder/resize (saved per form), density toggle, bulk select + delete, export, keyboard navigation, skeleton/error/empty states, and a card layout on mobile.
- **Command palette (`nu-shell.js`):** `Ctrl/Cmd+K` (or `/`) jumps to any sidebar destination or runs quick actions (theme, sidebar). Header search button added; `[` toggles the sidebar.
- **Toasts:** icons, dismiss button, hover-to-pause, stack limit and screen-reader announcements.

### v5.0.24 (Current Release)
- **Form Builder Grid Alignment & Per-Row Options:** Added per-row column presets (`1 Col`, `2 Cols`, `3 Cols`, `4 Cols`) and element alignment controls (`Left`, `Center`, `Right`) to row action bars, persisting `row_align` in JSON schema and dynamically computing grid start/span offsets.
- **12-Column Canvas Spans in Edit Mode:** Refactored edit-mode grid rendering (`_applyColSpan`) to set exact `grid-column` span properties on a 12-column `.nb-row-body` CSS grid, fixing card size rendering issues in edit mode.
- **Responsive Viewport Fallbacks:** Enforced CSS media queries and canvas rules so fields automatically fall back to 2 columns (`span 6`) on tablet devices and 1 column full-width (`span 12`) on mobile viewports.
- **Properties Panel Width Preset Buttons:** Extended column span presets in the properties panel (`1`, `2`, `3`, `4`, `6`, `8`, `12`) with active highlighting for all 12 grid span selections.
- **Realistic Canvas Element Previews:** Added live mockups (`_buildCardCanvasPreview`) directly on canvas cards rendering text inputs, textareas, dropdowns, checkboxes, subforms, and buttons during design mode.
- **Browse Table Actions First Column:** Moved the Actions column (containing Edit, View, Certificates, and Delete action buttons) to the **first column** across all browse forms and subform grids.
- **Asset Architecture Modernization:** Upgraded asset pipeline architecture across `assets/css/nubuilder-next.css` (semantic CSS grid tokens, viewport scaling, dark mode variables) and `assets/js/` (`nb-form-builder.js`, `nubuilder-next.js`, `nusubform.js`, `nb-ace-manager.js`), implementing responsive 12-column grid canvas rendering, real-time control previews, and re-architected first-column browse action tables.

### v5.0.0 - v5.0.23
- **Visual Refresh & CSS Tokens (`assets/css/nubuilder-next.css`):** Complete semantic CSS custom properties scale (`--primary`, `--primary-hover`, `--bg-app`, `--bg-card`, `--border-color`, `--shadow-sm`, `--shadow-md`, `--radius-lg`, `--color-success`, `--color-info`, `--surface-elevated`, `--focus-ring`) covering both Light and Dark mode variations.
- **Card-Based UI & Skeleton Loaders:** Modern card layouts with soft shadows, subtle borders, and content-aware skeleton placeholder loaders (`.nu-skeleton`) during data fetches.
- **16 Configurable Dashboard Widget Types:** Stat/KPI, Metric Trend (Sparkline + % Change), Sparkline, Bar Chart, Line Chart, Area Chart, Pie Chart, Doughnut Chart, Radar Chart, Data Table, Quick Links, Quick Actions, Activity Feed, Progress Bar, External iFrame, and Custom HTML.
- **Dynamic SQL Placeholders:** Full placeholder support (`{{user_id}}`, `{{role}}`, `{{today}}`, `{{this_month}}`, `{{this_year}}`) evaluated at runtime against the active session user and date context.
- **Redis High-Performance Cache & Session Storage (`core/Cache.php`, `core/RedisSessionHandler.php`):** High-speed singleton caching wrapper using `phpredis` (supporting Unix domain sockets and TCP) with prefixing (`nuvis:*`), `remember()` pattern, and custom session handler registered before `session_start()`.
- **Strict Session Management & MFA:** Configured custom session cookie identifier `nuvisstudioxssid` in `core/SessionBootstrap.php` with strict security flags, TOTP Two-Factor Authentication, and SAML 2.0 / OIDC SSO integration.

---

## Architecture

```
nuvis/
├── config.php                    # Global configuration
├── config.local.php.example      # Local override template
├── config.user_fields.php.example
├── index.php                     # App shell (login + SPA dashboard)
├── setup.php                     # Web-based installer
├── migrate.php                   # Migration runner from nuBuilder Forte 4.9
├── manifest.json                 # PWA manifest
├── sw.js                         # PWA service worker
├── .htaccess                     # Apache rewrite + security rules
│
├── install.sql                   # Base schema + seed data
├── install_phase3.sql            # Phase 3 schema additions
├── install_phase4.sql
├── install_phase5.sql
├── install_phase6.sql
├── install_phase7.sql
├── install_appcloner.sql         # App Cloner module schema
├── install_dashboard_widgets.sql # Dashboard widget schema
├── install_email.sql             # Email system schema
├── install_email_forms.sql       # Email-forms link schema
├── install_errorlog.sql          # Error log schema
├── install_menus_seed.sql        # Menu seed data
├── migrate_phase6_patch1.sql     # Phase 6 hotfix
├── migrate_phase8.sql            # Phase 8 migration
├── migrate_widget_icon.sql       # Widget icon migration
│
├── core/
│   ├── Database.php              # PDO database abstraction layer
│   ├── Auth.php                  # Session auth, lockout, 2FA hooks, CSRF, RBAC
│   ├── Cache.php                 # Redis high-performance cache wrapper
│   ├── RedisSessionHandler.php   # Native Redis PHP session handler
│   ├── Audit.php                 # Audit trail logging
│   ├── Api.php                   # REST API foundation (token auth + rate limiting)
│   ├── FormRenderer.php          # Metadata-to-form renderer
│   ├── ReportRenderer.php        # SQL report renderer (tables + Chart.js)
│   ├── QueryExecutor.php         # Safe SELECT-only SQL executor
│   ├── MenuRenderer.php          # Dynamic menu renderer
│   ├── EmailService.php          # SMTP email service (full)
│   ├── Emailer.php               # Lightweight emailer wrapper
│   ├── ErrorLogger.php           # Structured error logging
│   ├── AppCloner.php             # App template clone engine (38KB)
│   ├── Workflow.php              # Workflow engine core
│   ├── PluginManager.php         # Plugin registration + loader
│   └── Api.php                   # Modern REST API layer
│
├── api/
│   ├── auth.php                  # Login / logout / me
│   ├── crud.php                  # Generic CRUD for any table
│   ├── form.php                  # Form runtime API
│   ├── report.php                # Report runtime API
│   ├── query.php                 # Query runtime API
│   ├── upload.php                # File upload handler
│   ├── export.php                # CSV / JSON export
│   ├── import.php                # CSV import
│   ├── gateway.php               # Central Unified REST API Gateway
│   ├── api_manager.php           # API Manager backend routes
│   ├── forgot_password.php       # Password recovery token verification & handler
│   ├── word_certificates.php     # Unified certificates template processing engine
│   ├── procedure.php             # Dynamic single-procedure runner endpoint
│   ├── procedures.php            # Procedures list and details management endpoint
│   ├── redis_actions.php         # Redis cache diagnostics and daemon API
│   ├── mfa.php                   # Two-Factor Authentication API
│   └── sso.php                   # Single Sign-On (SAML/OIDC) API
│
├── modules/
│   ├── dashboard/                # KPI cards, activity feed, quick actions
│   ├── forms/                    # Form builder + runtime preview
│   ├── reports/                  # Report builder + table/chart output
│   ├── queries/                  # Query builder + parameter forms
│   ├── users/                    # User CRUD with roles
│   ├── roles/                    # Role/permission matrix
│   ├── menus/                    # Menu builder (with Dual role CSV/JSON sync)
│   ├── audit/                    # Audit trail viewer
│   ├── files/                    # File manager (incorporating local Uppy and OneDrive picker)
│   ├── import_export/            # Data import/export tools
│   ├── appcloner/                # App template cloner UI
│   ├── calendar/                 # Calendar / scheduler module
│   ├── documents/                # Document management module
│   ├── errorlog/                 # Error log viewer
│   ├── inspector/                # Schema/data inspector
│   ├── integrations/             # External integrations (Outgoing Webhooks UI + Live receiver)
│   ├── password/                 # Password change UI
│   ├── password_policy/          # Password policy manager
│   ├── plugins/                  # Plugin management UI
│   ├── widgets/                  # Dashboard widget builder
│   ├── workflow/                 # Workflow engine UI (Kanban, Visual diagrammer, Simulator)
│   ├── ai/                       # AI integration module
│   ├── updater/                  # System Updater (Git auto-update & config manager)
│   ├── word_certificates/        # Word (.docx) & PDF Template Certificate Designer
│   ├── api_manager/              # Centralized Rest API Route & Logs Controller
│   ├── barcode/                  # Products & dynamic barcode label manager
│   ├── report_dashboards/        # Custom report dashboard widgets
│   ├── procedures/               # Centralized Custom PHP Procedures (functions) block designer
│   ├── system_demo_files/        # Interactive Developer Demo (Custom LEFT JOIN forms, workflows, automation)
│   ├── developer_settings/       # System-wide variables, user custom fields, Redis status & toggles
│   ├── projects/                 # Multi-Project Manager (isolation, switcher, member access, versions, SQL export)
│   ├── mfa/                      # MFA enrollment and backup codes management UI
│   └── sso/                      # SSO identity provider configuration UI
│
└── assets/
    ├── css/nubuilder-next.css    # Design system, CSS custom properties, responsive 12-col grid tokens, dark mode
    ├── js/nubuilder-next.js      # App framework, SPA router, Browse Data Grids (Actions 1st Col), Lookup & Select controllers
    ├── js/nb-form-builder.js     # Visual Form designer canvas (12-col grid spans, device viewports, live previews, row presets)
    ├── js/nb-ace-manager.js      # Global Ace Code Editor instance controller with Beautify and Popup Sync
    ├── js/nusubform.js           # Subform runtime rendering, Inline Inputs, first-column actions grid & lookup handler
    ├── js/nu-select2-init.js     # Dynamically bounds standard dropdowns to Select2 search boxes
    ├── js/nu-errorlogger.js      # Captures Javascript client-side errors and logs them to the server
    └── js/email-manager.js       # Admin panel client logic for template, credentials, and live send logs
```

---

## Requirements

- PHP 8.1+ (Fully compatible up to PHP 8.3)
- MySQL 5.7+ or MariaDB 10.3+ (Fully compatible with MySQL 8.0+)
- Apache with `mod_rewrite` enabled
- Modern browser (Chrome, Firefox, Safari, Edge)

---

## Quick Start

1. Upload all files to your web server
2. Visit `setup.php` in your browser
3. Follow the environment check and database installation steps
4. Login with `globeadmin` / `password` — **change this immediately**

## Manual Installation

```bash
# Import base schema
mysql -u root -p < install.sql

# Set directory permissions
chmod 755 uploads/ logs/

# Copy and edit config
cp config.local.php.example config.local.php
```

---

## Core Features

### Design System
- Fully responsive — mobile to desktop
- Dark / Light / Auto theme support
- Card-based UI with CSS custom properties
- Accessible keyboard navigation
- PWA ready (service worker + web manifest)

### Multi-Project Support
- Isolated project environments with dedicated forms, reports, queries, procedures, menus, workflows, and dashboards
- Server-side context switching via `ProjectContext`
- See [Multi-Project Metadata Isolation Guide](docs/multi_project_guide.md) for full architecture details

### Plugins System
- Extensible modular architecture allowing plugins located under `plugins/`
- Lifecycle hooks and custom workflow extensions
- See [Plugin Architecture & Extension Guide](docs/plugins_guide.md) for details

### Security
- Password hashing (bcrypt)
- Login attempt lockout
- Session timeout + regeneration
- Custom session cookie `nuvisstudioxssid` with strict HTTP security flags
- Two-Factor Authentication (TOTP MFA) & Backup Codes
- Single Sign-On (SAML / OIDC SSO)
- CSRF token generation
- Role-based permissions (RBAC)
- Full audit trail

### API Layer
- Token-based authentication with rate limiting
- Generic CRUD endpoints for any table
- Form / Report / Query runtime APIs
- File upload with type/size validation
- CSV / JSON import and export

### Runtime Renderers
- **FormRenderer** — turns JSON metadata into live data-entry forms with validation
- **ReportRenderer** — executes SQL safely, renders tables or Chart.js charts
- **QueryExecutor** — parameter binding, SELECT-only validation, CSV export

### Builders
- Visual form builder with drag-and-drop canvas, multi-level layout flattening, instant rendering, per-row column presets, element alignments, and 12-column grid layout controls
- Report builder with SQL + column definitions + chart support
- Query builder with parameter definitions
- Menu builder for navigation management
- Embedded PHP code editor (Ace) for advanced browse/query customization
- Signature pad / picture canvas fields for drawn signatures and images

### Business Modules
- Dashboard with KPI cards and activity feed
- User / Role / Permission management
- Audit trail with pagination
- File manager with upload/delete
- Import / Export tools (CSV, JSON)
- Email system (SMTP, templates, form-triggered emails)
- Error log viewer with structured output
- Password management + configurable password policies
- App Cloner — clone and template entire applications
- Dashboard widgets (configurable, icon support)
- System Updater — Git auto-update & config manager (restricted to globeadmin/admin)
- Multi-Project Management — isolated projects, header switcher, access control, version snapshots, and single-project SQL export

---

## Technical Developments & Changelog

Over the course of recent developments, we have performed deep architectural refactoring to secure compatibility, clean developer experience, and robustness:

### 🛠️ Compatibility & Engine Hardening
- **PHP 8.1 - 8.3 Support:** Resolved string type-casting discrepancies within core PHP modules. Fixed built-in string functions (`strlen()`, `str_replace()`, `explode()`, `stripos()`) encountering null inputs by explicitly casting all nullable runtime variables to standard strings (e.g. `(string)$val`).
- **MySQL 8.0+ Integration & SQLite Fallbacks:** Adapted JSON column handling and DDL schema scripts to comply with strict MySQL 5.7+ and 8.0+ rules. Escaped raw JSON strings inside the consolidated database installer by doubling single quotes (`''`) rather than standard backslash escaping (`\'`) to resolve JSON syntax issues during table seeding. Added `PRAGMA table_info` and primary key fallback queries inside `nu_get_pk()` to support SQLite database drivers gracefully without runtime query mapping failures.
- **Dynamic Auto-Migrations:** Added robust self-healing try-catch DDL functions (`nu_ensure_custom_user_columns()` and menu columns check helpers) ensuring system tables (`nu_menus`, `nu_users`) dynamically adapt on-the-fly and prevent schema-related 500 errors on fresh installations or upgrades.
- **Safe SQL Splitter Parser:** Re-implemented the database installer parser (`setup.php`) to utilize a robust character-by-character SQL parsing algorithm. This correctly distinguishes semicolons inside single or double-quoted string literals (like inline CSS style statements in HTML templates) from statement delimiters, avoiding syntax crashes during database setup.

### 🖥️ Form Builder & Property Panel Enhancements
- **Per-Row Column Presets & Alignments:** Added interactive row action buttons (`1 Col`, `2 Cols`, `3 Cols`, `4 Cols` and `Left`, `Center`, `Right`) to control row element width and alignment dynamically.
- **12-Column CSS Grid Canvas:** Updated `.nb-row-body` grid styling so field column spans (`1` through `12`) scale accurately in edit mode.
- **First-Column Actions in Browse Tables:** Positioned the Actions column (Edit, View, Certificates, Delete) as the first column in browse forms and subform tables for improved usability.
- **Fixed Viewport Fullscreen Mode:** Toggled via an interactive `⛶ Fullscreen` button or by pressing the `Escape` key. Toggling expands `#formBuilderCard` to cover 100% of the viewport (fixed position with high-z-index), scales the `.nb-builder-wrap` canvas container height dynamically, and locks background scroll on the document body.
- **Row & Group Drag-and-Drop Rearranging:** Added a highly intuitive, global drag-and-drop mechanism `_attachRowContainerDragAndDrop(container)` allowing users to rearrange rows and containers (Groups/Tabs) up or down. Supports transferring rows between canvas levels and nested group containers, utilizing dynamic class toggles and drag handle isolates.
- **Customizable Group Containers:** Added setting configurations (`_openGroupSettingsModal`) for collapsible/accordion toggles, background colors, header colors, custom borders, text colors, and border widths, rendered at runtime with circular expand/collapse icons on the right.

---

## Database Schema

### Core Tables
- `nu_users` — User accounts with roles (includes JSON `usr_custom_fields`)
- `nu_roles` — Role definitions
- `nu_permissions` — Permission catalog
- `nu_role_permissions` — Role-permission mapping

### Builder Tables
- `nu_forms` — Form metadata (JSON layout supporting nested layers, custom timestamps, browse PHP)
- `nu_reports` — Report metadata (SQL + columns)
- `nu_queries` — Query metadata (SQL + parameters)
- `nu_menus` — Navigation structure (role JSON access + CSV fallback)

### Multi-Project Tables
- `nu_projects` — Project definitions and configurations
- `nu_project_members` — User-to-project access control mapping
- `nu_project_tables` — Form table registry per project
- `nu_project_versions` — JSON metadata snapshot version control

---

## Security Notes

- All query executor SQL is validated as SELECT-only
- Dangerous keywords blocked at parser level
- Parameters bound with type sanitization
- File uploads restricted by type and size
- Sessions regenerate on login with custom cookie name `nuvisstudioxssid`
- Passwords hashed with bcrypt
- `.htaccess` blocks direct access to `config.php`, `core/`, `sessions/`

---

## License

Open Source — MIT License
