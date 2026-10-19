# End-to-End Guide: Using Developer Settings Global Hashes in Calculated Fields & SQL

Global hashes in Nuvis StudioX allow you to declare system-wide or user-specific constants (such as tax rates, regional fees, or environment settings) once in a central place and then securely use them across the entire application—both in backend SQL queries and in frontend calculated fields.

This comprehensive guide explains how to define, use, and verify global hashes.

---

## 1. Setting Up Global Hashes in Developer Settings

To configure a global custom setting (such as a standard tax rate):

1. Log in to the application as a user with the **globeadmin** role.
2. In the sidebar navigation under the **Admin Tools** group, click on **Developer Settings**.
3. Under the **System Settings & Global Hashes** section, click the **➕ Add Custom Field** button.
4. Fill in the row properties:
   - **Field Label**: Enter a descriptive label, e.g., `Standard Tax Rate`.
   - **Field Key (Unique Name)**: Enter a unique key in lower snake_case, e.g., `tax_rate`. This key will be used to reference the value.
   - **Value**: Enter the default value, e.g., `0.15` (for a 15% tax rate).
   - **Global Hash?**: Check this checkbox to make sure the key is exposed globally.
5. Click **💾 Save Settings**. The page will reload, caching the settings and synchronizing the system.

---

## 2. Using Global Hashes in Calculated Fields

Nuvis StudioX's calculation engine automatically resolves global hashes inside **Calculated Field** formulas. You can reference your central values using **two highly convenient syntaxes**:

### Syntax A: Field Notation `{tax_rate}` (Best Practice)
Use the standard field notation syntax. When the calculation engine evaluates the formula, it checks the active form for a field named `tax_rate` first. If no field with that name exists on the form, it automatically falls back to looking up the key inside your global settings.

* **Example Formula (Order Total with Tax)**:
  ```
  {subtotal} * (1 + {tax_rate})
  ```
* **Example Formula (Tax Amount only)**:
  ```
  {subtotal} * {tax_rate}
  ```

### Syntax B: SQL Hash Notation `##tax_rate##`
For consistency with SQL query placeholders, you can also use double-hash syntax directly within calculated field formulas on the client side.

* **Example Formula (Order Total with Tax)**:
  ```
  {subtotal} * (1 + ##tax_rate##)
  ```
* **Example Formula (Tax Amount only)**:
  ```
  {subtotal} * ##tax_rate##
  ```

### How to set this up in the Form Builder:
1. Open the **Forms** list and edit or create a Form.
2. Drag or drop a field onto your canvas and change its type to **Calculated** under the General settings tab in the Properties panel.
3. In the field's properties, look for the **Formula** textarea.
4. Input your formula referencing your global hashes (e.g., `{subtotal} * ##tax_rate##` or `{subtotal} * {tax_rate}`).
5. Save the form. When you open a record, any changes to the `{subtotal}` input field will instantly update your calculated field with the central tax rate applied!

---

## 3. Viewing Global Hashes in the Browser Developer Console

Nuvis StudioX makes it extremely simple to inspect, debug, and query all loaded global hashes and user metadata directly in the browser's developer console.

### A. Automatic Page Load Log
Every time you load or reload a page in Nuvis StudioX, the system automatically outputs a formatted console table.
* Press `F12` (or right-click and select **Inspect**) to open the browser Developer Tools, then navigate to the **Console** tab.
* You will see a dedicated log entry:
  ```
  📂 [Nuvis StudioX] Global Hashes & User Meta Loaded:
  ```
  accompanied by an interactive, structured table displaying all active global keys and their respective values.

### B. Friendly Helper Function: `nuGetGlobalHashes()`
You can retrieve the entire collection of active global hashes as a clean JavaScript object by running:
```javascript
nuGetGlobalHashes();
```
* **To extract a specific value (such as tax rate) in the console**:
  ```javascript
  nuGetGlobalHashes().tax_rate;
  ```

### C. Direct Global Object: `nuUserMeta`
Alternatively, you can access the underlying live global state object directly via:
```javascript
nuUserMeta;
```
* **To check a specific value directly**:
  ```javascript
  nuUserMeta.tax_rate;
  ```

---

## 4. Using Global Hashes in SQL Queries

Since the settings are fully integrated into the backend core permission and resolution engines, you can use the SQL hash notation (`##key##`) inside custom form list conditions, drop-down lookups, reports, and custom SQL queries.

* **Example Lookup/Where Clause**:
  ```sql
  SELECT * FROM products WHERE tax_category = 'standard' AND tax_rate <= ##tax_rate##
  ```
The Nuvis StudioX server-side resolution engine securely replaces `##tax_rate##` with the sanitized value `0.15` prior to execution, preventing any security risks or SQL injection.
