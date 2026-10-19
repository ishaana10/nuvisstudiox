# App Cloner Module

## Overview
The App Cloner module allows system administrators to duplicate/template an entire nuvis application. It handles copying system metadata tables (forms, reports, menus, users, and roles), application-specific tables (schema + contents), stored procedures, database functions, triggers, and scheduled events, as well as migrating files and assets to a new target directory. It supports background execution for large jobs, live progress polling, direct connection testing, and customizable SQL exports (optionally zipped/gzip-compressed) for offline deployments.

---

## Architecture & Key Files
- **`modules/appcloner/appcloner.php`**: The wizard-style administrative frontend built with the Nuvis design system.
- **`api/appcloner.php`**: REST entry point for polling, connection tests, executing the background worker, or generating SQL exports.
- **`api/appcloner_worker.php`**: CLI background job worker script executed asynchronously via PHP CLI to avoid web timeout limitations.
- **`core/AppCloner.php`**: The core application logic and SQL compilation engine (38KB file handling table introspection and cross-connection loading).

---

## Technical Details

### Options for Cloning
The cloner provides selective extraction criteria (bitmask/array identifiers):
1. **System Tables/Views** (`nu_*` and general environment systems).
2. **User Tables/Views** (customized application structures).
3. **System Records** (Nuvis StudioX core records, configuration rows).
4. **App Definitions** (form structures, menu hierarchies, reports definition metadata).
5. **User Data** (actual row inputs in non-system tables).
6. **Functions** (database custom functions).
7. **Procedures** (stored procedures).
8. **Triggers** (database-triggered event procedures).
9. **Events** (MySQL event scheduler actions).

### Background Execution Pattern
To handle large databases without web timeouts:
1. `api/appcloner.php?action=start` saves job details to `temp/nu_cloner_job_[jobId].json`.
2. It launches `api/appcloner_worker.php [jobId]` in the background using `pclose(popen(...))` on Windows or `exec("php ... &")` on Unix.
3. The background task writes steps to `temp/nu_cloner_progress_[jobId].json`.
4. The client polls `api/appcloner.php?action=progress&jobId=[jobId]` dynamically updating the logger in the UI.

---

## REST API Reference

| Endpoint | Method | Action | Description |
|---|---|---|---|
| `/api/appcloner.php?action=list_tables` | `GET` | N/A | Lists non-system tables in the database with row estimates. |
| `/api/appcloner.php?action=list_databases` | `POST` | N/A | Tests target SMTP/DB credentials and returns databases on that server. |
| `/api/appcloner.php?action=start` | `POST` | `start` | Starts the clone worker background task. |
| `/api/appcloner.php?action=progress` | `GET` | `progress` | Polls progress steps for a specific worker thread. |
| `/api/appcloner.php?action=export_sql` | `POST` | `export_sql` | Downloads a customizable SQL script representation. |

---

## Usage Examples

### Starting a Background Clone Job via fetch API
```javascript
const payload = {
  targetDB: "my_cloned_instance",
  targetHost: "127.0.0.1",
  targetUser: "db_user",
  targetPass: "secure_pass",
  targetCharset: "utf8mb4",
  targetPort: 3306,
  targetPath: "/var/www/my_cloned_app",
  sourcePath: "/var/www/source_app",
  databaseMode: "clear", // or 'fail' or 'create'
  fileMode: "overwrite",
  copyFiles: true,
  opts: [1, 2, 3, 4, 5], // What to clone
  insertType: "INSERT IGNORE",
  dryRun: false,
  schemaOnly: false,
  includeTables: ["customers", "orders"],
  webhookUrl: ""
};

fetch('api/appcloner.php?action=start', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(payload)
})
.then(res => res.json())
.then(data => console.log("Job started with ID:", data.jobId));
```
