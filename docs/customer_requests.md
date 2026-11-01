# Customer Requests Module

## Overview
The Customer Requests module is a **reusable, fully customisable request/ticketing system** that drops into any Nuvis StudioX project. A "customer request channel" bundles together four primitives the platform already provides, so you never wire them by hand:

- **Form** — the intake data model, fields, validation and layout (editable in the Forms builder).
- **Public share** — a tokenised public page (`public_form.php`) customers use to submit a request without logging in. Each submission inserts a record, emails your team and the customer, and auto-starts the pipeline.
- **Workflow** — status tracking: stages, transitions, SLA, history and automation hooks (editable in the Workflow module).
- **Email templates** — customer/staff notifications (editable in Email Settings).

One project can run **several channels** (e.g. Support Tickets *and* Service Requests), each with its own form, pipeline and public link.

---

## Architecture & Key Files
- **`modules/customer_requests/customer_requests.php`** — the module UI: setup screen, Kanban tracking board, request detail drawer, settings.
- **`api/customer_requests.php`** — backend actions (project-scoped, auth-gated).
- **`core/CustomerRequests.php`** — the reusable engine: schema, built-in templates, and the one-call `provision()` that creates everything.
- **Tables** — `nu_cr_configs` (one row per channel) and `nu_cr_notes` (internal notes). The actual request records live in the channel's own form table (e.g. `cr_help_desk`), and status lives in `nu_workflow_instances`.

The two tables are created by `install.sql` on a fresh install, and by `NuCustomerRequests::ensureSchema()` on existing installs (idempotent, runs on first API call).

---

## Easy setup (for developers)
1. Open **Customer Requests** in the sidebar.
2. Pick a starting template — **Support Tickets**, **Service Requests**, **Contact Enquiries**, **Bug Reports** — or **Use an existing form**.
3. Give the channel a name, a reference prefix (e.g. `TKT-`) and a staff notification email. Leave "public intake link" on to get a shareable page.
4. **Create channel.** That single action:
   - creates the intake form + its table (UUID primary key),
   - creates a workflow bound to the form, with coloured stages, SLA hours and transitions,
   - enables a public share link with staff + customer email,
   - registers the channel config.

Everything is immediately editable afterwards — nothing is a black box.

### Programmatic setup
```php
require_once __DIR__ . '/core/CustomerRequests.php';
$config = NuCustomerRequests::provision($db, $projectId, $userId, [
    'name'           => 'Help Desk',
    'template'       => 'support',      // support | service | contact | bug | existing
    'reference_prefix' => 'TKT-',
    'notify_email'   => 'ops@example.com',
    'public_enabled' => 1,
    // for template 'existing': 'form_code' => 'my_form'
]);
```

---

## Tracking & status
The board shows one column per workflow stage, coloured to match. Each card shows the reference, customer, subject, age and an **Overdue** badge when the stage SLA is exceeded. Open a card to:
- see all submission fields,
- **advance the status** (buttons are the workflow transitions from the current stage; blocked steps are disabled with a reason),
- read the **history timeline** and add **internal notes**.

Staff can also log a request manually (**+ New request**) — it enters the pipeline exactly like a public submission.

---

## Customising
From **Settings** on the board:
- **Channel** — name, icon, reference prefix, description.
- **Notifications & public intake** — staff email, enable/disable the public link, copy the link.
- **Customise** — deep links to:
  - *Edit the intake form* → Forms module (fields, validation, layout, theme),
  - *Edit the status pipeline* → Workflow module (stages, transitions, SLA, automations),
  - *Edit email templates* → Email Settings (`cr_request_received`, `cr_status_changed`).

Because the pieces are ordinary forms/workflows/templates, anything the builders support is available here.

---

## Connecting to other modules
Use **workflow transition hooks** (in the Workflow module) to make a request act on the rest of your project as it moves through the pipeline. Each transition or stage "on enter" can run actions:
- `update_record` — e.g. mark a linked order/booking **Completed** when a request reaches *Resolved*.
- `create_record` — e.g. open a task in another table.
- `send_email` — extra notifications beyond the built-in ones.
- `call_webhook` — hand off to an external system.

See `docs/update_another_table_guide.md` and `docs/workflow.md` for hook syntax. Field values from the request record are available to these actions, so you can match on a reference number or foreign key.

---

## API reference (`api/customer_requests.php`)
All actions are POST unless noted, scoped to the current project.

| Action | Method | Purpose |
| --- | --- | --- |
| `templates` | GET | List built-in setup templates |
| `list_configs` | GET | Channels in this project (+ public link, totals, available forms) |
| `get_config?id=` | GET | One channel + stages + form fields |
| `setup` | POST | Provision a new channel *(admin)* |
| `save_config` | POST | Update channel settings *(admin)* |
| `delete_config` | POST | Remove a channel; keeps form/table/workflow *(admin)* |
| `board?id=&q=` | GET | Requests grouped by status |
| `request?id=&record=` | GET | One request: fields, timeline, notes, available actions |
| `advance` | POST | Move a request to another status |
| `add_note` | POST | Add an internal note |
| `create_request` | POST | Log a request manually |
| `stats?id=` | GET | Per-stage counts + overdue total |

Setup/configuration actions require an **admin** or **globeadmin** role; viewing and working requests follow normal module access.

---

## Notes
- Deleting a channel removes only the config link — the underlying form, table and workflow are kept so no customer data is lost. Delete those separately if you no longer need them.
- The module appears in the static sidebar automatically. If a project uses a custom menu (`nu_menus`), add a menu item of type *module* pointing at `customer_requests` via the Menus module.
