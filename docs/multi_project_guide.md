# Multi-Project Metadata Isolation Guide

Nuvis supports multi-project metadata isolation, enabling single-instance deployments to host multiple isolated application environments (projects) with dedicated forms, reports, queries, procedures, menus, workflows, and dashboards.

## Architecture & Data Model

### Global System Tables (Shared Instance)
Core system tables remain shared globally across all projects on the instance:
- `nu_users` — System user accounts and credentials
- `nu_roles` & `nu_role_permissions` — Role definitions and permissions
- `nu_system_settings` — System-wide settings and developer configurations
- `nu_audit_log` & `nu_error_log` — Instance-wide security and error logs

### Project-Scoped Tables
App metadata and builder definitions are strictly isolated by `project_id`:
- `nu_forms` — Dynamic form definitions and layout schemas
- `nu_reports` — Report definitions, SQL queries, and PDF templates
- `nu_queries` — Custom query definitions
- `nu_procedures` — Custom PHP procedure scripts
- `nu_menus` — Menu navigation hierarchies
- `nu_workflows` — Workflow definitions, stages, and transition hooks
- `nu_report_dashboards` — Custom report dashboard layouts
- `nu_form_versions` — Form layout snapshot history

### Project Context Management (`core/ProjectContext.php`)
Active project context is managed server-side via `ProjectContext::getId()` and stored in `$_SESSION['nu_project_id']`.
- Users with the `globeadmin` role can access and switch between all active projects seamlessly.
- Standard users are restricted to projects where they are assigned as owner or member in `nu_project_members`.
- Requests to project-scoped endpoints automatically filter SQL queries by `project_id`.

## Context Switching & UI Indicators
The active project is indicated in the top navigation header with an active project badge (e.g. `⚡ default`) alongside a project switcher dropdown. Administrators can quickly create new projects using the **New Project** button or manage project members and snapshot versions via the **Projects** module.

## Access rules enforced by the APIs

- **Generic CRUD (`api/crud.php`)** – a table registered to a project (`nu_project_tables`) is only reachable while that project is active (globeadmin must switch project first). Tables with no registration are treated as default-project data. `nu_*` system tables stay globeadmin-only, checked on the table name actually used.
- **Forms / versions / rollback, report dashboards (`run_report`)** – every lookup is filtered by the active `project_id`.
- **Files (`api/file.php`, `api/upload.php?action=delete`, `api/document.php`)** – `nu_files.project_id` is stamped on upload; a file from another project is reported as "not found". Files with no project (uploaded before this change) count as default-project files.
- **Default project access** – the default project is members-only. A user with no membership lands in no project (id 0) and reaches nothing. New users are added to it automatically and migration `010_default_project_members.sql` adds existing users. To restore the old open behaviour set `$nuConfig['projectDefaultOpenAccess'] = true;` in `config.local.php`.
- **Role-by-form permissions (`api/crud.php`)** – besides the project check, the user's role needs view/add/edit/delete in `nu_role_form_permissions` on a form (in the active project) that uses the table, or on the `*` row. A table no form uses is denied (`$nuConfig['crudAllowUnmappedTables'] = true` allows read-only access). `globeadmin` always passes.
- **CSRF and Origin (`core/SecurityGate.php`, run from `config.php`)** – signed-in POST/PUT/PATCH/DELETE to `/api` and `/modules` need the session token (`X-CSRF-Token` header or `nu_csrf` field) and a same-site Origin/Referer. `assets/js/nu-csrf.js` adds the header to every same-origin fetch/XHR. Config: `csrfEnforce`, `csrfExemptScripts`, `allowedOrigins`.
- **Procedures** – run stored PHP via `eval`: globeadmin only, audited before each run, and disabled when `NU_ENV=production` unless `$nuConfig['enableProcedures'] = true`.
- **Tenants** – `nu_users`, `nu_roles` and `nu_projects` have a `tenant_code` (default `default`). Non-globeadmin users only manage users/roles of their own tenant, cannot assign or edit `globeadmin`, and never open another tenant's project even if a membership row exists. Give a customer's users, roles and projects the same `tenant_code` to separate them on one install.
- **Fail closed** – if the project lookup fails, `ProjectContext::setId()` returns `false`.
- **UUID installs** – user ids are never cast to integers; project member/owner columns are widened to `VARCHAR(36)` automatically when `userIdType = 'uuid'`.
