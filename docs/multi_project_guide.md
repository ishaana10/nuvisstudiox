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
- **Default project access** – by default every signed-in user may open the default project. To make it members-only add `$nuConfig['projectDefaultOpenAccess'] = false;` to `config.local.php`.
- **Fail closed** – if the project lookup fails, `ProjectContext::setId()` returns `false`.
- **UUID installs** – user ids are never cast to integers; project member/owner columns are widened to `VARCHAR(36)` automatically when `userIdType = 'uuid'`.
