# Demo App: Custom Joins & Automated Multi-Table Updates

This guide provides the complete blueprint, database schemas, and step-by-step configurations to build a fully functional **Service Request & Tracking App** in Nuvis StudioX.

This demo app illustrates:
1. **Custom Joins inside Browse Grid**: Displaying the descriptive service type name (e.g., "Software Install") instead of a raw database ID (e.g., `3`).
2. **Automated Multi-Table Updates**: When a staff member logs a service record against a request, the source customer request's status is automatically updated from `"Pending"` to `"Completed"`.

---

## 1. Database Schema Setup

To initialize this demo app, run the following SQL commands in your MySQL database to create the tables and seed them with sample data (Note: These tables are pre-populated automatically in fresh installations of `install.sql`):

```sql
-- Table A: Service Types (Lookup Table)
CREATE TABLE IF NOT EXISTS `demo_service_types` (
    `service_type_id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_name` VARCHAR(100) NOT NULL,
    `service_code` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table B: Customer Requests (Main Tracker)
CREATE TABLE IF NOT EXISTS `demo_customer_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_name` VARCHAR(100) NOT NULL,
    `service_type_id` INT NOT NULL,
    `request_details` TEXT,
    `status` VARCHAR(50) DEFAULT 'Pending',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table C: Staff Services Log (Action/Automation Trigger)
CREATE TABLE IF NOT EXISTS `demo_staff_services` (
    `service_log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `staff_name` VARCHAR(100) NOT NULL,
    `service_notes` TEXT,
    `service_date` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Sample Data
INSERT IGNORE INTO `demo_service_types` (`service_type_id`, `service_name`, `service_code`) VALUES
(1, 'Hardware Diagnostics & Repair', 'HW_DIAG'),
(2, 'Software Installation & Config', 'SW_INSTALL'),
(3, 'Network Troubleshooting', 'NET_TRIAL');

INSERT IGNORE INTO `demo_customer_requests` (`request_id`, `customer_name`, `service_type_id`, `request_details`, `status`) VALUES
(101, 'Alice Smith', 1, 'My laptop screen is flickering.', 'Pending'),
(102, 'Bob Johnson', 2, 'Need latest MS Office installed.', 'Pending'),
(103, 'Charlie Brown', 3, 'Wi-Fi connection drops every 10 mins.', 'Pending');
```

---

## 2. Implementing Custom Joins in `demo_customer_requests` Browse

To display the actual **Service Name** instead of the `service_type_id` number in your browse grid, configure it using either of the following two options:

### Option A: Using "Browse PHP" (Global SQL)
In the Form Builder settings for `demo_customer_requests`, navigate to the **PHP / CSS** tab, find the **Browse PHP** editor, and insert:

```php
<?php
// Define custom browse query with the _display suffix alias
$nuSql = "
    SELECT
        demo_customer_requests.*,
        demo_service_types.service_name AS service_type_id_display
    FROM demo_customer_requests
    LEFT JOIN demo_service_types ON demo_service_types.service_type_id = demo_customer_requests.service_type_id
";
```

### Option B: Field-Level Properties (UI Only)
On the form canvas, select the `service_type_id` field element. On the right-hand **Properties Panel**, set:
* **Join SQL**:
  ```sql
  LEFT JOIN demo_service_types ON demo_service_types.service_type_id = demo_customer_requests.service_type_id
  ```
* **Join Display Field**:
  ```text
  demo_service_types.service_name
  ```

---

## 3. Automating Request Status Updates (Pending ➡️ Completed)

When a staff member logs a new service action in the `demo_staff_services` form, we want to automatically change the corresponding `demo_customer_requests` status from `"Pending"` to `"Completed"`.

### Step-by-Step Configuration:

1. Open the **Form Builder** for the `demo_staff_services` form.
2. Go to the **PHP / CSS** tab.
3. Locate the **PHP After Save** editor.
4. Input the following code block:

```php
<?php
/**
 * PHP After Save - Automatically complete the associated customer request
 * when a staff service action is logged.
 */

// 1. Fetch the request ID being serviced from the form field values
$requestId = (int)($request_id ?? '#request_id#');

if ($requestId > 0) {
    try {
        // 2. Fetch active PDO database instance
        $db = nu_db();

        // 3. Execute the status update on the customer requests table
        $sql = "UPDATE `demo_customer_requests` SET `status` = 'Completed' WHERE `request_id` = ?";

        $stmt = $db->prepare($sql);
        $stmt->execute([$requestId]);

        // Log the success of this automated operation
        nu_log("Demo App Automation: Updated customer request #" . $requestId . " status to Completed.", "demo_automation");

    } catch (Throwable $e) {
        // Log errors without blocking standard staff logging saves
        error_log("[Demo App Automation Error] " . $e->getMessage());
    }
}
```

5. Click **Save** on the form.

---

## 4. Testing Your Demo App

1. Open your `demo_customer_requests` list. You will notice that the **Service Type** column beautifully displays "Hardware Diagnostics & Repair" instead of `1`.
2. Note that the request's status is currently `"Pending"`.
3. Open the `demo_staff_services` form, add a log entry with `request_id` = `101`, set the `staff_name` as `"John Doe"`, and click **Save**.
4. Go back to your `demo_customer_requests` list. You will see that request `#101`'s status has automatically changed to `"Completed"`!
