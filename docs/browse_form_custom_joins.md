# Implementing Custom Joins in Form Browse Grids (Nuvis StudioX)

This document provides a detailed, step-by-step guide and practical examples on how to implement custom database joins for dynamic forms.

Using your specific SQL query:
```sql
SELECT * FROM pbrder_bapp
LEFT JOIN agent ON agent.ag_id = pbrder_bapp.p_agent
LEFT JOIN station ON station.station_id = pbrder_bapp.p_location
LEFT JOIN zzzzsys_user ON zzzzsys_user.zzzzsys_user_id = pbrder_bapp.p_officer
LEFT JOIN service_type ON service_type.sev_id = pbrder_bapp.p_servicetype
```

We will show you exactly how to show the descriptive child values (e.g., Agent Name, Station Name, User Name, Service Type Name) in your browse grid instead of raw foreign key IDs (like ID `55`).

In Nuvis StudioX, there are **two primary methods** to achieve this.

---

## Method 1: Using "Browse PHP" (Global SQL Override)

This is the most direct way to override the dynamic query executed by the browse grid. By writing a small PHP block in the form's **Browse PHP** settings, you can define a custom `$nuSql` string.

### How the Grid Handles Display Values:
The front-end browse grid (`nubuilder-next.js`) is designed with a powerful, built-in display convention:
* For a field named `p_agent`, if your SQL select statement provides a column named `p_agent_display`, the browse grid will **automatically display the value of `p_agent_display`** instead of the raw `p_agent` ID.
* It does this while keeping the original ID intact so that editing, deleting, and selecting actions still work perfectly using the correct primary key!

### Step-by-Step Implementation:

1. Open the **Form Builder** for your form (`pbrder_bapp`).
2. Go to the **Form Settings** tab or panel (where metadata like Form Name, Table Name, Page Size, etc. are configured).
3. Find the **Browse PHP** input area.
4. Input the following PHP code:

```php
<?php
// Define custom SQL query that selects display-friendly names with the _display suffix.
$nuSql = "
    SELECT
        pbrder_bapp.*,
        agent.ag_name                  AS p_agent_display,
        station.station_name           AS p_location_display,
        zzzzsys_user.username          AS p_officer_display,
        service_type.sev_name          AS p_servicetype_display
    FROM pbrder_bapp
    LEFT JOIN agent ON agent.ag_id = pbrder_bapp.p_agent
    LEFT JOIN station ON station.station_id = pbrder_bapp.p_location
    LEFT JOIN zzzzsys_user ON zzzzsys_user.zzzzsys_user_id = pbrder_bapp.p_officer
    LEFT JOIN service_type ON service_type.sev_id = pbrder_bapp.p_servicetype
";
```

*(Note: Replace column names like `agent.ag_name`, `station.station_name`, `zzzzsys_user.username`, and `service_type.sev_name` with the actual descriptive columns in your tables if they are named differently).*

5. Save the form. When you open the form, the browse table will execute your custom SQL, detect the `_display` aliases, and automatically display the actual names instead of IDs!

---

## Method 2: Using Field-Level Join Settings (No Code / Builder UI)

If you prefer a modular, drag-and-drop approach without writing manual SQL overrides for the entire table, you can configure joins **directly on individual canvas fields**.

The backend form renderer (`api/form.php`) automatically reads these field configurations and dynamically structures the JOIN SQL for you.

### Step-by-Step Configuration:

1. Open the **Form Builder** for your form.
2. Select your lookup/foreign-key fields one-by-one to open their **Properties Panel** on the right side.
3. Under the **Validation** or **Advanced** property tabs, locate the **Join SQL** and **Join Display Field** configuration fields.
4. Fill in the following configurations for each field:

#### 1. Field: `p_agent`
* **Join SQL**:
  ```sql
  LEFT JOIN agent ON agent.ag_id = pbrder_bapp.p_agent
  ```
* **Join Display Field**:
  ```text
  agent.ag_name
  ```

#### 2. Field: `p_location`
* **Join SQL**:
  ```sql
  LEFT JOIN station ON station.station_id = pbrder_bapp.p_location
  ```
* **Join Display Field**:
  ```text
  station.station_name
  ```

#### 3. Field: `p_officer`
* **Join SQL**:
  ```sql
  LEFT JOIN zzzzsys_user ON zzzzsys_user.zzzzsys_user_id = pbrder_bapp.p_officer
  ```
* **Join Display Field**:
  ```text
  zzzzsys_user.username
  ```

#### 4. Field: `p_servicetype`
* **Join SQL**:
  ```sql
  LEFT JOIN service_type ON service_type.sev_id = pbrder_bapp.p_servicetype
  ```
* **Join Display Field**:
  ```text
  service_type.sev_name
  ```

5. **Save the Form**.

### How Method 2 Works Under the Hood:
When loading the list data, `api/form.php` scans the form layout fields and builds the final query using the fields' properties:

```php
// api/form.php extracts the config dynamically:
$flatLayout = nu_flatten_layout_for_grid($layout);
foreach ($flatLayout as $f) {
    $jSql  = trim($f['join_sql'] ?? '');
    $jDisp = trim($f['join_display_field'] ?? '');
    $fName = nu_field_name($f);
    if ($jSql !== '' && $jDisp !== '') {
        $joins[] = $jSql;
        $selectCols[] = "{$jDisp} AS `{$fName}_display`";
    }
}
```

The server dynamically constructs the `_display` column names (e.g., `p_agent_display`), which are then received by the frontend and displayed seamlessly in the browse grid.

---

## Summary of Benefits

| Feature | Method 1: Browse PHP | Method 2: Field-Level Properties |
| :--- | :--- | :--- |
| **Effort** | Easy (Single copy-paste of SQL query) | Very Easy (No code, visual inputs) |
| **Flexibility** | **High** (You can write custom `WHERE`, `GROUP BY`, or complex nested queries in PHP) | **Medium** (Standard joins and simple display projections) |
| **Maintenance** | Managed in one central settings box | Managed per-field on the canvas |
| **Grid Search/Filter** | Supported | Supported out-of-the-box |
