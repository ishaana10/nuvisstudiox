# Automatically Updating Another Table in Nuvis StudioX

This document provides a comprehensive guide and concrete code examples on how to automatically update another database table when a record is saved in Nuvis StudioX (for example, updating an order/booking as **completed** when a certificate with matching reference number is issued).

We will cover the two recommended ways to implement this:
1. **Using PHP After Save** (Database/Transaction Hook)
2. **Using Workflows** (Business Logic Hook with Webhooks / Actions)

---

## Method 1: Using PHP After Save (Server-Side Database Hook)

The **PHP After Save** block executes on the server side immediately after a record has been successfully inserted or updated in the database. During execution, individual field values are injected as local variables (e.g., `$field_name`) and are also substituted using token-based placeholders (e.g., `#field_name#`).

### Scenario
We have an `issued_certificates` form table. When a certificate is saved:
1. It contains a `cert_reference` (reference number).
2. We want to find the corresponding record in the `orders` (or `bookings`) table.
3. We want to update that target record's status to `'completed'`.

### Step-by-Step Implementation:

1. Open the **Form Builder** for your form (`issued_certificates`).
2. Go to the **PHP / CSS** tab.
3. Find the **PHP After Save** section.
4. Input the following PHP code:

```php
<?php
/**
 * PHP After Save - Automatically update the order status to "completed"
 * when a matching certificate reference is saved.
 */

// 1. Retrieve the certificate reference number from the saved record fields.
// In Nuvis StudioX, fields are available directly as local variables or token placeholders.
$reference = trim($cert_reference ?? '#cert_reference#');

if (!empty($reference)) {
    try {
        // 2. Obtain the database connection
        $db = nu_db(); // Returns the active PDO instance

        // 3. Execute the update query on the other table
        $sql = "UPDATE `orders` SET `status` = 'completed', `updated_at` = NOW() WHERE `order_reference` = ?";

        $stmt = $db->prepare($sql);
        $stmt->execute([$reference]);

        // Optional: Log the automation action for audit purposes
        nu_log("Automatically updated order status to completed for reference: " . $reference, "after_save_automation");

    } catch (Throwable $e) {
        // Log errors to prevent breaking the core form save process
        error_log("[After Save Automation Error] " . $e->getMessage());
    }
}
```

5. Click **Save** on the form. Any future certificates saved with a reference number will trigger an immediate status update on the matching order record in the database!

---

## Method 2: Using the Workflow Engine

If your business process is managed through our multi-stage pipeline system, you can handle table updates as a workflow transition event.

### Scenario
When a workflow instance of an order moves through the **"Issue Certificate"** transition, we want to trigger an automated database update to mark the target table record as **completed**.

### Step-by-Step Implementation:

1. Navigate to the **Workflow & Simulator Module** (`modules/workflow/workflow.php`).
2. Edit or select your active Workflow definition.
3. Find the transition representing the certificate issuance (e.g. from stage `"Pending Certificate"` to `"Completed"`).
4. For this transition, set the **Transition Hook** (`wft_hook`) to `call_webhook` or a Custom PHP execution block:
   * **Built-in `update_record` Hook**: If the workflow is bound to the target table, choosing `update_record` as the hook automatically updates the target table row's status column to match the destination stage code (e.g., `'completed'`) under the hood.
   * **Webhook Hook**: Selecting `call_webhook` sends a POST request with transition metadata to your webhook handler (e.g. `api/webhook.php`), where you can capture the reference number and run a custom PDO query:

```php
// Inside your webhook listener or workflow transition handler:
$payload = json_decode(file_get_contents('php://input'), true);

if ($payload && $payload['event'] === 'workflow_advance' && $payload['action'] === 'Issue Certificate') {
    $recordId = $payload['instance_id']; // or order ID from payload

    $db = NuDatabase::getConnection();
    $db->query("UPDATE `orders` SET `status` = 'completed' WHERE `id` = ?", [$recordId]);
}
```

---

## Summary comparison

| Feature | Method 1: PHP After Save | Method 2: Workflow |
| :--- | :--- | :--- |
| **Execution Point** | Immediately after DB row save | On advancing a workflow pipeline stage |
| **Ideal For** | Quick updates, simple table-to-table syncing | Standardized business approvals, multi-role pipelines |
| **Logging** | Manual `nu_log()` entries | Automatically logged to `nu_workflow_history` |
| **Failure Tolerance** | Must handle exceptions to prevent interrupting save | Sandboxed so webhook/mail failures do not lock forms |
