# Dashboard Widgets Module

## Overview
The Dashboard Widgets module is a responsive, highly configurable portal dashboard rendering system. It lets administrators create, drag-and-drop, resize, and style dynamic data widgets (ranging from KPI statistics, trend indicators with sparklines, bar/line/area/pie/doughnut/radar charts, data tables, quick-action button grids, activity feeds, external iFrames, progress bars, and freeform HTML) scoped globally, to specific user roles, or to individual user accounts.

---

## Architecture & Key Files
- **`assets/css/nubuilder-next.css`**: CSS Design Tokens (`:root` and `[data-theme="dark"]`), responsive grid systems, and card styling.
- **`modules/widgets/widgets.php`**: Foundational template rendering and compiling dynamic widgets using structured PHP functions.
- **`modules/widgets/widgets.js`**: Core drag-and-drop layout manager, live preview parser, Font Awesome icon filter, and auto-refresh timers.
- **`modules/dashboard/widget_api.php`**: REST endpoint backend executing safe queries, revoking widget objects, and writing serialized configs.

---

## Supported Widget Types

| Widget Type | Purpose | Configuration Requirements |
|---|---|---|
| `stat` | Primary KPI metric value with subtitle & optional drill-down link | SQL returning a `value` column |
| `metric_trend` | KPI stat with inline sparkline & percentage change indicator | SQL returning `value` and optional `prev_value` |
| `sparkline` | Compact micro-line trend chart | SQL returning `label` and `value` columns |
| `chart_bar` | Standard vertical bar chart with rounded corners | SQL returning `label` and `value` columns |
| `chart_line` | Continuous line chart | SQL returning `label` and `value` columns |
| `chart_area` | Filled area line chart | SQL returning `label` and `value` columns |
| `chart_pie` | Full circular pie chart | SQL returning `label` and `value` columns |
| `chart_doughnut` | Cutout doughnut chart | SQL returning `label` and `value` columns |
| `chart_radar` | Multi-axis radar/spider chart | SQL returning `label` and `value` columns |
| `table` | Responsive tabular data grid | SELECT SQL query |
| `list` | Quick link list button group | Text lines: `Label\|form_code` or `Label\|url` |
| `quick_actions` | Multi-column quick action grid with icons | Text lines: `Label\|form_code\|fa-icon` |
| `activity` | Audit activity feed log | SQL returning `time`, `user`, and `text` columns |
| `progress` | Percent completion bar | SQL returning `done` and `total` columns |
| `iframe` | Embedded web page or external dashboard | Target URL & Height in pixels |
| `custom` | Freeform HTML markup | HTML string |

---

## SQL Placeholders

SQL queries executed by widgets automatically support dynamic variable substitution:

- `{{user_id}}`: Active logged-in user ID
- `{{role}}`: Active user role code (e.g., `admin`, `manager`)
- `{{today}}`: Current date (`YYYY-MM-DD`)
- `{{this_month}}`: First day of current month (`YYYY-MM-01`)
- `{{this_year}}`: First day of current year (`YYYY-01-01`)

---

## Technical Details

### Widgets Database Schema (`nu_dashboard_widgets`)
Properties, dimensional span definitions, roles, and JSON-encoded configurations are indexed in `nu_dashboard_widgets`:

```sql
CREATE TABLE IF NOT EXISTS nu_dashboard_widgets (
    widget_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_user_id  INT UNSIGNED  DEFAULT NULL,      -- Scoped to specific user (Null = Global default)
    widget_role     VARCHAR(50)   DEFAULT NULL,      -- Default role placement (e.g. 'sales_clerk')
    widget_type     VARCHAR(32)   NOT NULL,          -- stat, chart_bar, chart_line, chart_area, chart_pie, chart_doughnut, chart_radar, table, list, quick_actions, activity, progress, iframe, custom
    widget_title    VARCHAR(100)  NOT NULL,
    widget_icon     VARCHAR(120)  DEFAULT NULL,      -- Font Awesome or direct Emoji glyphs
    widget_width    TINYINT(1)    NOT NULL DEFAULT 2, -- grid-column span (1 = 3 cols, 2 = 6, 3 = 9, 4 = 12 cols)
    widget_height   TINYINT(1)    NOT NULL DEFAULT 1, -- grid-row span height
    widget_position INT UNSIGNED  NOT NULL DEFAULT 0, -- Reorder sort values
    widget_config   TEXT          DEFAULT NULL,      -- JSON-encoded options (SQL strings, colors, HTML content)
    widget_active   TINYINT(1)    NOT NULL DEFAULT 1,
    widget_created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wgt_user (widget_user_id),
    INDEX idx_wgt_role (widget_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## REST API Reference

| Endpoint | Method | Action | Description |
|---|---|---|---|
| `/modules/dashboard/widget_api.php?action=list` | `GET` | `list` | Resolves target widget configurations. |
| `/modules/dashboard/widget_api.php?action=add` | `POST` | `add` | Writes new widget record. |
| `/modules/dashboard/widget_api.php?action=update` | `POST` | `update` | Updates an existing widget configuration. |
| `/modules/dashboard/widget_api.php?action=remove` | `POST` | `remove` | Deactivates a specific dashboard widget. |
| `/modules/dashboard/widget_api.php?action=reorder` | `POST` | `reorder` | Updates widget display position offsets. |
