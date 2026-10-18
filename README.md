# Nuvis StudioX

A premium, modern web development application for **Nuvis Technologies** (`web.nuvistechnologies.com.fj`) — PHP/MySQL, fully self-hosted low-code platform.

>  **Host:** A2Hosting (cPanel) · **Stack:** PHP8.1+, MySQL 5.7+, Apache

---

## Recent Major Enhancements & Upgrades (May 2026)

### 🎨 Visual Refresh & CSS Design Tokens System
- **Expanded Token Set (`assets/css/nubuilder-next.css`):** Complete semantic CSS custom properties scale (`--primary`, `--primary-hover`, `--bg-app`, `--bg-card`, `--border-color`, `--shadow-sm`, `--shadow-md`, `--radius-lg`, `--color-success`, `--color-info`, `--surface-elevated`, `--focus-ring`) covering both Light and Dark mode variations.
- **Card-Based UI & Skeleton Loaders:** Modern card layouts with soft shadows, subtle borders, and content-aware skeleton placeholder loaders (`.nu-skeleton`) during data fetches.

### 📊 Modernized Dashboard Widgets & Advanced Charting
- **16 Configurable Widget Types:** Stat/KPI, Metric Trend (Sparkline + % Change), Sparkline, Bar Chart, Line Chart, Area Chart, Pie Chart, Doughnut Chart, Radar Chart, Data Table, Quick Links, Quick Actions, Activity Feed, Progress Bar, External iFrame, and Custom HTML.
- **Dynamic SQL Placeholders:** Full placeholder support (`{{user_id}}`, `{{role}}`, `{{today}}`, `{{this_month}}`, `{{this_year}}`) evaluated at runtime against the active session user and date context.
- **Auto-Refresh & Responsive Grid:** Live widget polling configured via `refresh_interval` (seconds) with content-aware skeleton loaders and a 12-column responsive layout grid.
- **Comprehensive Guide:** Updated documentation in `docs/widgets.md`.

### ⚡ Redis High-Performance Cache & Session Management
- **Redis Service & Session Storage (`core/Cache.php`, `core/RedisSessionHandler.php`):** High-speed singleton caching wrapper using `phpredis` (supporting Unix domain sockets and TCP) with prefixing (`nuvis:*`), `remember()` pattern, and custom session handler registered before `session_start()`.
- **Developer Diagnostics Panel (`modules/developer_settings/developer_settings.php` & `api/redis_actions.php`):** Live Redis memory usage diagnostics, latency benchmark tool, A2 Hosting daemon trigger (`~/.redis.on`), cache flushing, and live cached key TTL inspector.

### 🔒 Security Hardening, MFA & SSO Integration
- **Strict Session Management:** Configured custom session cookie identifier `nuvisstudioxssid` in `core/SessionBootstrap.php` with `use_strict_mode`, `cookie_httponly`, `cookie_secure`, and `cookie_samesite = Strict`.
- **Two-Factor Authentication (TOTP MFA):** Secret key generation, QR code setup, OTP verification, and 8-digit backup code recovery (`api/mfa.php` and `modules/mfa/mfa.php`).
- **Single Sign-On (SSO):** SAML 2.0 and OpenID Connect (OIDC) identity provider configuration and auth callback gateway (`api/sso.php` and `core/Sso/`).

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
    ├── css/nubuilder-next.css    # Design system (CSS custom properties, tokens, dark mode)
    ├── js/nubuilder-next.js      # App framework + utilities (includes Lookup & Select controllers)
    ├── js/nb-form-builder.js     # Drag-and-drop Visual Form designer client-side canvas
    ├── js/nb-ace-manager.js      # Global Ace Code Editor instance controller with Beautify and Popup Sync
    ├── js/nusubform.js           # Subform runtime rendering, Inline Inputs & lookup handler
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
- Visual form builder with drag-and-drop canvas, multi-level layout flattening, and instant rendering
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

## Completed Extended Modules

### 🔄 Workflow Engine & Simulator
An advanced, production-ready business process automation module comprising:
- **Visual Builder:** Powered by Mermaid.js integration for beautiful, stage-colored flowchart representations.
- **Workflow Simulator:** Sandbox simulator where users run mock data JSON objects against defined transition conditional rules (`wft_condition`), dynamically highlighting the evaluated path on the flowchart in real time.
- **Kanban Board:** Dynamic, stage-colored drag-and-drop style Kanban board mapping active workflow instances across standard stages (Active, Completed, Rejected, Cancelled).
- **Transition Action Hooks:** Fully integrated action triggers (`wft_hook`) executing automatic emails (`send_email`), invoking external webhooks (`call_webhook`), or updating linked record database values (`update_record`) upon successful advances.
- **Robust Schema:** Implemented definitions, stage pipelines, instances, transition condition strings, and history trackers across `nu_workflows`, `nu_workflow_stages`, `nu_workflow_transitions`, `nu_workflow_instances`, and `nu_workflow_history`.

### 🌐 Outgoing Webhooks & Integrations
A highly configurable and secure external notification hub:
- **Customizable Requests:** Fully customizable outgoing webhook dispatchers with supports for distinct HTTP methods (GET, POST, PUT, PATCH) and dynamic key-value HTTP headers.
- **JSON Payload Templates:** Custom JSON or text template rendering with deep nested dot-notation placeholder resolution (e.g., `{{data.field}}`).
- **Trigger Actions:** Hooked directly into standard application saves, dynamic form builder saves, REST API CRUD events, successful logins (`Auth.php`), and workflow engine stage transitions.
- **Interactive Verification:** Live testing console equipped with a local mock webhook receiver (`api/webhook_demo_listener.php`) displaying delivery payloads and logs (`webhook_demo_logs.json`) interactively.

### 📥 Import / Export Wizard
A modern data-import pipeline built for rapid, reliable bulk uploads:
- **Interactive Wizard:** Multi-step wizard supporting file selection, CSV parsing on the client, and mapping.
- **Fuzzy Auto-Mapping:** Automatically analyzes CSV headers and matches them to physical database columns using an intuitive name-based fuzzy logic algorithm.
- **Flexible Parser & Type Casts:** Intelligent backend importing (`api/import.php`) featuring automatic date-parsing, numeric formatting cleanup (stripping symbols/currency), boolean normalization, and NULL-handling.
- **Security Checkpoints:** Strictly limits bulk-write operations to authorized tables, preventing overwrite attempts on sensitive core metadata tables (e.g. `nu_users`, `nu_forms`) by non-globeadmin users.

### ✉️ Email Administration & Send Log
A fully managed SMTP system and notification tracker:
- **Tabbed Settings UI:** Dynamic email configuration panel integrating SMTP credentials management, Email Template creation, and a centralized Send Log.
- **Send Log Popup Overlay:** Fully integrated modal detailed popup viewer presenting complete email transmission metadata (recipients, subjects, templates, errors, and actual raw mail headers) instantly upon row selection.
- **Transactional Hooks:** Mail automation bound directly to form submissions, custom scripts, and transition workflows.

### ⚙️ System Updater & Git Manager
The official system administrative terminal (locked to `globeadmin` or `admin` roles):
- **Git Connection Tester:** Visual interface equipped with an interactive **⚡ Test Git Connection** verification mechanism validating local executable paths and working directories.
- **Interactive Initialization:** Automates Git setups through an in-place "Initialize & Link Git Repository" command in instances where standard `.git` directories are missing.
- **Remote Update Panel:** Select, fetch, and pull commits directly from remote branches on the configured repository root, with strict overrides bypasses targeting modern web hosting permissions (e.g. `www-data` context safe directory checks).

### 📝 Word (.docx) & PDF Certificates Template Engine
A comprehensive certificates designer allowing administrators to map layout variables to document templates:
- **Format Support:** Supports both Word `.docx` templates (processed backend using lightweight `ZipArchive` extraction, node modification, and replacement) and raw HTML template maps.
- **Recursive XML-to-HTML Parser:** Includes a sophisticated recursive table-aware Word XML parsing algorithm (`convertDocxXmlToHtml`) capable of translating nested paragraphs, bold, italic, underline, alignment, borders, colspans, and custom cell background colors into responsive inline-styled HTML layouts, generating pixel-perfect PDFs automatically.
- **Dynamic Checkbox & Helper Placeholders**: Automatically creates user-friendly template helpers for all system and custom fields (such as `{{field_name_box}}` rendering as ☑/☐, and `{{field_name_yesno}}` rendering as Yes/No).
- **Responsive Context Actions:** Leverages a lightweight client-side pre-fetch engine in `nubuilder-next.js` to render custom certificates action buttons directly onto browse rows and header actions, launching styled certificate previews in dynamic modal overlays.

### 🔑 Advanced Forgot Password & Recovery Module
A secure, self-service user login recovery framework:
- **Configurable Password Policies:** Governed by an interactive administration switch `forgot_password_enabled` within `nu_system_settings`.
- **Token-Based Verification:** Leverages cryptographically secure random SHA-256 tokens stored inside the self-healing table `nu_password_resets` enforcing strict 1-hour expiration.
- **HTML Transactional Emails:** Dispatches automated modern HTML reset links leveraging the core `EmailService`.
- **Credential Protection Rules:** Secure Reset password view that enforces password complexity checks, handles visual transition cards, and validates input passwords against recent password reuse histories.

### 🚀 Unified REST API Gateway & Interactive Log
A robust gateway pattern bridging internal application layers with third-party networks:
- **Dynamic Routing:** Centered around `api/gateway.php` providing deep sub-route resolving (e.g. `/customers/123`).
- **Secure Token Authentication:** Employs bearer authentication tokens stored in `nu_api_tokens`, verifying corresponding role permissions to enforce strict RBAC endpoints.
- **Full Form Integration:** Automatically triggers backend validations, security controls, `NuAudit` entries, and executes `form_insert`, `form_update`, or `form_delete` outgoing webhook hooks dynamically on API mutations.
- **Visual Logs Analyzer:** An interactive diagnostic dashboard (`modules/api_manager/`) displaying client IP addresses, execution metrics, payload structures, response headers, and HTTP status outputs stored inside the self-healing table `nu_api_logs`.

### 💾 Centralized Custom PHP Procedures (Functions)
A robust modular script repository enabling server-side execution of custom PHP code blocks:
- **Database Storage:** Saves code snippets securely by unique identifiers inside the self-healing `nu_procedures` database table.
- **Sandboxed Test Console:** Embedded Code Editor combined with an interactive test runtime panel allowing developers to mock inputs, execute procedures safely, and review execution time, output buffers, and exceptions.
- **Unified Client-Server Bridge**: Allows immediate client-side invocation via a simple asynchronous javascript API `callPHP(code, params, callback)` or its alias `runProcedure(code, params, callback)`. Under the hood, procedures are executed via static helper `NuProcedure::run($code, $params)` or global functions `nu_run_procedure()` / `run_procedure()`.

### 📁 Multi-Project Management & Metadata Isolation
A multi-tenant project isolation engine within a single Nuvis instance:
- **Project Context & Switcher**: Header dropdown allowing users to switch active project context in real time.
- **Isolated Metadata**: Filters forms, menus, reports, queries, procedures, and workflows by `project_id`.
- **Project Members & Security**: Assigns users to projects via `nu_project_members` with roles (`owner`, `admin`, `member`), enforcing RBAC across non-`globeadmin` users.
- **Single-Project SQL Exports**: AppCloner integration (`exportProject`) exporting project definitions and registered form data tables into portable SQL bundles.
- **Version Snapshots & Rollback**: Point-in-time JSON metadata snapshot creation and restoration (`nu_project_versions`).

### 🏷️ Barcodes, Products & Self-Healing Inventory
A dedicated products tracking system:
- **Self-Healing Schema**: Automatically checks for, creates, and populates the `nu_products` table on database connect using session-cached flags, preventing runtime errors.
- **Interactive UI Operations**: Integrated barcode lookup and printing triggers, including custom print-label buttons that safely override popup blockers and handle standard browser form submissions without page reloads.

### ☁️ Microsoft OneDrive Picker Integration
Provides a seamless hybrid cloud and local file upload system:
- **OneDrive Hybrid Uploads**: Standard file upload controls inside the visual Form Builder can be configured to load files directly from the cloud using the integrated Microsoft OneDrive SDK.
- **Uppy Unified Upload Dashboard**: Configured dynamically based on field attributes (`allowed_extensions`, `max_files`, `max_file_size_mb`), fallback to global variables, and supports deleting physically uploaded files via `api/upload.php?action=delete` locked with CSRF headers.

---

## Technical Developments & Changelog

Over the course of recent developments, we have performed deep architectural refactoring to secure compatibility, clean developer experience, and robustness:

### 🛠️ Compatibility & Engine Hardening
- **PHP 8.1 - 8.3 Support:** Resolved string type-casting discrepancies within core PHP modules. Fixed built-in string functions (`strlen()`, `str_replace()`, `explode()`, `stripos()`) encountering null inputs by explicitly casting all nullable runtime variables to standard strings (e.g. `(string)$val`).
- **MySQL 8.0+ Integration & SQLite Fallbacks:** Adapted JSON column handling and DDL schema scripts to comply with strict MySQL 5.7+ and 8.0+ rules. Escaped raw JSON strings inside the consolidated database installer by doubling single quotes (`''`) rather than standard backslash escaping (`\'`) to resolve JSON syntax issues during table seeding. Added `PRAGMA table_info` and primary key fallback queries inside `nu_get_pk()` to support SQLite database drivers gracefully without runtime query mapping failures.
- **Dynamic Auto-Migrations:** Added robust self-healing try-catch DDL functions (`nu_ensure_custom_user_columns()` and menu columns check helpers) ensuring system tables (`nu_menus`, `nu_users`) dynamically adapt on-the-fly and prevent schema-related 500 errors on fresh installations or upgrades.
- **Safe SQL Splitter Parser:** Re-implemented the database installer parser (`setup.php`) to utilize a robust character-by-character SQL parsing algorithm. This correctly distinguishes semicolons inside single or double-quoted string literals (like inline CSS style statements in HTML templates) from statement delimiters, avoiding syntax crashes during database setup.

### 🖥️ Form Builder & Property Panel Enhancements
- **Fixed Viewport Fullscreen Mode:** Toggled via an interactive `⛶ Fullscreen` button or by pressing the `Escape` key. Toggling expands `#formBuilderCard` to cover 100% of the viewport (fixed position with high-z-index), scales the `.nb-builder-wrap` canvas container height dynamically, and locks background scroll on the document body.
- **Row & Group Drag-and-Drop Rearranging:** Added a highly intuitive, global drag-and-drop mechanism `_attachRowContainerDragAndDrop(container)` allowing users to rearrange rows and containers (Groups/Tabs) up or down. Supports transferring rows between canvas levels and nested group containers, utilizing dynamic class toggles and drag handle isolates.
- **Customizable Group Containers:** Added setting configurations (`_openGroupSettingsModal`) for collapsible/accordion toggles, background colors, header colors, custom borders, text colors, and border widths, rendered at runtime with circular expand/collapse icons on the right.
- **Automatic MySQL Column Insertion Positioning:** When adding a new database column dynamically for custom form fields, `api/forms.php` analyzes current columns, detects the first system/audit column (e.g., `user_id`, `created_at`), and structures an `ALTER TABLE ... ADD COLUMN ... AFTER` query positioning the custom field before standard audit blocks.
- **Quiet Field Evaluations:** Silenced unneeded and noisy client-side developer console errors stemming from blank optional properties or missing placeholders in form inputs to ensure clean browser error logging.

### 🛡️ Core Security & Performance
- **Menu Security Fallbacks & CSV/JSON Double Sync:** Enhanced `NuMenuRenderer` to retrieve menu listings via `SELECT * FROM nu_menus` and dynamically default missing database schema values at runtime. Simultaneously writes role constraints across both `menu_role_access` (JSON) and legacy `menu_roles` (comma-separated string) to maintain absolute backward compatibility.
- **User Custom Fields Schema:** Structured custom user fields to write as JSON sequences directly inside the `usr_custom_fields` column of the `nu_users` table, with support for customizable dynamic database lookups (`lookup_table` and `lookup_field`).
- **JS Dynamic Script Scope Security:** Changed system JavaScript module definitions (such as Roles, Users, Webhooks) from block-scoped `const` to global-scoped `var` definitions to prevent `Uncaught SyntaxError` redeclaration errors when modules are dynamically loaded or re-evaluated inside the SPA application shell.
- **Inline UUID Handlers:** Enforced strict string wrapping (using quotes) when rendering Hyphenated UUIDs inside inline HTML event handlers (such as `onclick="Users.openEdit('<?php echo $u['usr_id']; ?>')"`), preventing Javascript syntax crashes.

### 📊 Advanced Data Grid, Subforms, & Calculated Fields
- **Select & Select2 Fields inside Subforms:** Supports rendering standard and Select2 fields in subform rows. Option values populate backend using `$decorateField` lambda and map frontend array saves. Select2 elements initialize dynamically via `window.nuInitSelect2`.
- **Dynamic Lookup Fields inside Subforms:** Lookups inside subform inline and modal edits utilize hidden inputs for ID tracking and editable text inputs with `data-lookup-*` attributes, communicating with `api/crud.php` and rendering matching resolved label keys (`{field_name}_display`).
- **Automated Lookup Modal & Typing Resolution:** Handled through document-level event listeners triggering `triggerAutoLookup` upon change or Enter. If a single matching record is found, it auto-populates mappings; otherwise, it spawns a searchable list pre-seeded with the search query.
- **Calculated Fields with Double-Hash & Global Fallbacks**: Updated calculated fields engine `nuCalculateFields` to support double-hash placeholder notations (e.g. `##tax_rate##`). It evaluates complex formulas, handling custom operators, parentheses, brackets, and falling back to global settings `window.nuUserMeta` when DOM fields are missing.
- **View SQL Debugging Tool:** Restricted exclusively to users with the `globeadmin` role. In Form Browse Grids, clicking `👁 View SQL` launches a copyable modal overlay displaying the raw base SQL, the final paginated SQL, and bind parameters returned by `nu_handle_list()`.
- **Forms list dashboard toggle & Search**: Includes an interactive UI toggle in `modules/forms/forms.php` enabling users to switch between Card View and detailed Browse Table View (persisted in `localStorage`), with an Enter-key or button-triggered search filter.
- **Global Ace Editor Externalization (`window.nbAce`)**: Centralized editor scripts inside `assets/js/nb-ace-manager.js`, introducing js-beautify formatting, distraction-free fullscreen overlays, pop-up tab synchronization, and custom height resizing handles.

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

### System Tables
- `nu_api_tokens` — API keys for incoming REST integrations
- `nu_api_logs` — Gateway request traffic details, execution times, payload structures, and headers
- `nu_procedures` — Centralized reusable Custom PHP scripting blocks
- `nu_password_resets` — Secure SHA-256 tokens for self-service recovery with expirations
- `nu_products` — Product catalog and inventory tracking
- `nu_api_usage` — Rate limiting logs
- `nu_audit_log` — Core activity logging (protecting system column fields automatically)
- `nu_files` — Upload tracking
- `nu_email_*` — Email config, templates, queue, send logs
- `nu_error_log` — Structured error log
- `nu_widgets` — Dashboard widget definitions
- `nu_app_templates` — App Cloner templates
- `nu_webhooks` — Outgoing webhook routes and parameters

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
