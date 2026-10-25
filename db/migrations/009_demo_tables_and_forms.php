<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasDemoTypes = false;
        try {
            $hasDemoTypes = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='demo_service_types'" : "SHOW TABLES LIKE 'demo_service_types'")->fetch();
        } catch (Exception $e) {}

        if (!$hasDemoTypes) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `demo_service_types` (
                `service_type_id` VARCHAR(36) NOT NULL,
                `name` VARCHAR(150) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (`service_type_id`)
            )" . ($driver === 'sqlite' ? "" : " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));

            $pdo->exec("INSERT INTO `demo_service_types` (`service_type_id`, `name`, `description`, `price`) VALUES
            ('a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d', 'Plumbing Maintenance', 'General plumbing repairs, leak detection, and pipe maintenance.', 150.00),
            ('b2c3d4e5-f6a7-8b9c-0d1e-2f3a4b5c6d7e', 'Electrical Inspection', 'Safety audit of wiring, outlets, and panel inspection.', 120.00),
            ('c3d4e5f6-a7b8-9c0d-1e2f-3a4b5c6d7e8f', 'HVAC System Service', 'Heating, ventilation, and air conditioning diagnostic and filter change.', 200.00)");
        }

        $hasDemoRequests = false;
        try {
            $hasDemoRequests = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='demo_customer_requests'" : "SHOW TABLES LIKE 'demo_customer_requests'")->fetch();
        } catch (Exception $e) {}

        if (!$hasDemoRequests) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `demo_customer_requests` (
                `request_id` VARCHAR(36) NOT NULL,
                `customer_name` VARCHAR(150) NOT NULL,
                `service_type_id` VARCHAR(36) NOT NULL,
                `request_details` TEXT DEFAULT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
                `created_at` " . ($driver === 'sqlite' ? "TEXT DEFAULT CURRENT_TIMESTAMP" : "DATETIME DEFAULT CURRENT_TIMESTAMP") . ",
                `updated_at` " . ($driver === 'sqlite' ? "TEXT DEFAULT CURRENT_TIMESTAMP" : "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP") . ",
                PRIMARY KEY (`request_id`)
            )" . ($driver === 'sqlite' ? "" : " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));

            $pdo->exec("INSERT INTO `demo_customer_requests` (`request_id`, `customer_name`, `service_type_id`, `request_details`, `status`) VALUES
            ('d4e5f6a7-b8c9-0d1e-2f3a-4b5c6d7e8f9a', 'Alice Smith', 'a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d', 'Main drain is running extremely slow and backing up.', 'Pending'),
            ('e5f6a7b8-c9d0-1e2f-3a4b-5c6d7e8f9a0b', 'Bob Johnson', 'b2c3d4e5-f6a7-8b9c-0d1e-2f3a4b5c6d7e', 'Living room outlets have no power. Breaker is not tripped.', 'Pending')");
        }

        $hasDemoStaff = false;
        try {
            $hasDemoStaff = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='demo_staff_services'" : "SHOW TABLES LIKE 'demo_staff_services'")->fetch();
        } catch (Exception $e) {}

        if (!$hasDemoStaff) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `demo_staff_services` (
                `service_log_id` VARCHAR(36) NOT NULL,
                `customer_request_id` VARCHAR(36) NOT NULL,
                `staff_notes` TEXT DEFAULT NULL,
                `service_date` " . ($driver === 'sqlite' ? "TEXT DEFAULT CURRENT_TIMESTAMP" : "DATETIME DEFAULT CURRENT_TIMESTAMP") . ",
                PRIMARY KEY (`service_log_id`)
            )" . ($driver === 'sqlite' ? "" : " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
        }

        // Demo forms seeding
        $hasNuForms = false;
        try {
            $hasNuForms = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_forms'" : "SHOW TABLES LIKE 'nu_forms'")->fetch();
        } catch (Exception $e) {}

        if ($hasNuForms) {
            $hasForm = $pdo->query("SELECT form_id FROM nu_forms WHERE form_code = 'demo_customer_requests'")->fetch();

            $layoutTypes = json_encode([
                ["type" => "row", "children" => [
                    ["name" => "name", "label" => "Service Name", "type" => "text", "required" => true, "col" => 6],
                    ["name" => "price", "label" => "Price ($)", "type" => "number", "required" => true, "col" => 6]
                ]],
                ["type" => "row", "children" => [
                    ["name" => "description", "label" => "Description", "type" => "textarea", "col" => 12, "rows" => 4]
                ]]
            ]);
            $browseTypes = json_encode([
                ["fieldname" => "name", "fieldlabel" => "Service Name", "width" => "200px", "align" => "left", "formatter" => "text", "sortable" => true],
                ["fieldname" => "price", "fieldlabel" => "Price", "width" => "120px", "align" => "right", "formatter" => "currency", "sortable" => true],
                ["fieldname" => "description", "fieldlabel" => "Description", "width" => "300px", "align" => "left", "formatter" => "text", "sortable" => true]
            ]);

            $layoutRequests = json_encode([
                ["type" => "row", "children" => [
                    ["name" => "customer_name", "label" => "Customer Name", "type" => "text", "required" => true, "col" => 6],
                    ["name" => "service_type_id", "label" => "Service Type", "type" => "select", "required" => true, "col" => 6, "options_source" => "table", "options_table" => "demo_service_types", "options_value_col" => "service_type_id", "options_label_col" => "name", "join_sql" => "LEFT JOIN demo_service_types ON demo_service_types.service_type_id = demo_customer_requests.service_type_id", "join_display_field" => "demo_service_types.name"]
                ]],
                ["type" => "row", "children" => [
                    ["name" => "request_details", "label" => "Request Details", "type" => "textarea", "col" => 12, "rows" => 4]
                ]],
                ["type" => "row", "children" => [
                    ["name" => "status", "label" => "Status", "type" => "text", "required" => true, "col" => 6, "default_value" => "Pending", "readonly" => true]
                ]]
            ]);
            $browseRequests = json_encode([
                ["fieldname" => "customer_name", "fieldlabel" => "Customer Name", "width" => "180px", "align" => "left", "formatter" => "text", "sortable" => true],
                ["fieldname" => "service_type_id", "fieldlabel" => "Service Type", "width" => "200px", "align" => "left", "formatter" => "text", "sortable" => true, "join_sql" => "LEFT JOIN demo_service_types ON demo_service_types.service_type_id = demo_customer_requests.service_type_id", "join_display_field" => "demo_service_types.name"],
                ["fieldname" => "request_details", "fieldlabel" => "Details", "width" => "280px", "align" => "left", "formatter" => "text", "sortable" => true],
                ["fieldname" => "status", "fieldlabel" => "Status", "width" => "120px", "align" => "center", "formatter" => "badge", "sortable" => true]
            ]);

            $layoutStaff = json_encode([
                ["type" => "row", "children" => [
                    ["name" => "customer_request_id", "label" => "Customer Request", "type" => "select", "required" => true, "col" => 6, "options_source" => "table", "options_table" => "demo_customer_requests", "options_value_col" => "request_id", "options_label_col" => "customer_name", "options_filter" => "status != 'Completed'", "join_sql" => "LEFT JOIN demo_customer_requests ON demo_customer_requests.request_id = demo_staff_services.customer_request_id", "join_display_field" => "demo_customer_requests.customer_name"],
                    ["name" => "service_date", "label" => "Service Date", "type" => "datetime", "required" => true, "col" => 6]
                ]],
                ["type" => "row", "children" => [
                    ["name" => "staff_notes", "label" => "Staff Service Notes", "type" => "textarea", "required" => true, "col" => 12, "rows" => 4]
                ]]
            ]);
            $browseStaff = json_encode([
                ["fieldname" => "customer_request_id", "fieldlabel" => "Customer", "width" => "180px", "align" => "left", "formatter" => "text", "sortable" => true, "join_sql" => "LEFT JOIN demo_customer_requests ON demo_customer_requests.request_id = demo_staff_services.customer_request_id", "join_display_field" => "demo_customer_requests.customer_name"],
                ["fieldname" => "staff_notes", "fieldlabel" => "Notes", "width" => "300px", "align" => "left", "formatter" => "text", "sortable" => true],
                ["fieldname" => "service_date", "fieldlabel" => "Date & Time", "width" => "160px", "align" => "left", "formatter" => "text", "sortable" => true]
            ]);
            $staffAfterSave = '$db = NuDatabase::getInstance();' . "\n" .
                              '$reqId = $customer_request_id;' . "\n" .
                              'if ($reqId) {' . "\n" .
                              '    $db->update("demo_customer_requests", ["status" => "Completed"], "request_id = ?", [$reqId]);' . "\n" .
                              '}';

            if (!$hasForm) {
                $pdo->prepare("INSERT INTO `nu_forms` (
                    `form_code`, `form_type`, `form_name`, `form_table`, `form_description`,
                    `form_layout`, `browse_layout`, `form_active`, `form_pk_type`, `form_table_mode`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                    'demo_service_types', 'main', 'Service Types', 'demo_service_types', 'Manage service types and pricing.',
                    $layoutTypes, $browseTypes, 1, 'uuid', 'existing'
                ]);

                $pdo->prepare("INSERT INTO `nu_forms` (
                    `form_code`, `form_type`, `form_name`, `form_table`, `form_description`,
                    `form_layout`, `browse_layout`, `form_active`, `form_pk_type`, `form_table_mode`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                    'demo_customer_requests', 'main', 'Customer Requests', 'demo_customer_requests', 'Manage customer requests.',
                    $layoutRequests, $browseRequests, 1, 'uuid', 'existing'
                ]);

                $pdo->prepare("INSERT INTO `nu_forms` (
                    `form_code`, `form_type`, `form_name`, `form_table`, `form_description`,
                    `form_layout`, `browse_layout`, `form_custom_php_after`, `form_active`, `form_pk_type`, `form_table_mode`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                    'demo_staff_services', 'main', 'Staff Services', 'demo_staff_services', 'Log staff service actions.',
                    $layoutStaff, $browseStaff, $staffAfterSave, 1, 'uuid', 'existing'
                ]);
            } else {
                $pdo->prepare("UPDATE `nu_forms` SET `form_layout` = ?, `browse_layout` = ? WHERE `form_code` = ?")->execute([$layoutTypes, $browseTypes, 'demo_service_types']);
                $pdo->prepare("UPDATE `nu_forms` SET `form_layout` = ?, `browse_layout` = ? WHERE `form_code` = ?")->execute([$layoutRequests, $browseRequests, 'demo_customer_requests']);
                $pdo->prepare("UPDATE `nu_forms` SET `form_layout` = ?, `browse_layout` = ?, `form_custom_php_after` = ? WHERE `form_code` = ?")->execute([$layoutStaff, $browseStaff, $staffAfterSave, 'demo_staff_services']);
            }
        }

        // Demo workflow seeding
        $hasNuWorkflows = false;
        try {
            $hasNuWorkflows = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_workflows'" : "SHOW TABLES LIKE 'nu_workflows'")->fetch();
        } catch (Exception $e) {}

        if ($hasNuWorkflows) {
            $hasWf = $pdo->query("SELECT wf_id FROM nu_workflows WHERE wf_code = 'customer_request_wf'")->fetch();
            if (!$hasWf) {
                $pdo->prepare("INSERT INTO `nu_workflows` (
                    `wf_code`, `wf_name`, `wf_description`, `wf_form_code`, `wf_active`
                ) VALUES (?, ?, ?, ?, ?)")->execute([
                    'customer_request_wf', 'Customer Request Workflow', 'A demo workflow tracking customer service requests through life cycle stage progressions.', 'demo_customer_requests', 1
                ]);
                $wfId = (int)$pdo->lastInsertId();
            } else {
                $wfId = (int)$hasWf['wf_id'];
            }

            $stageStmt = $pdo->prepare("SELECT wfs_id, wfs_code FROM nu_workflow_stages WHERE wfs_wf_id = ?");
            $stageStmt->execute([$wfId]);
            $existingStages = $stageStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

            if (empty($existingStages) || !isset($existingStages['Pending']) || !isset($existingStages['In Progress']) || !isset($existingStages['Completed'])) {
                $pdo->prepare("DELETE FROM `nu_workflow_transitions` WHERE `wft_wf_id` = ?")->execute([$wfId]);
                $pdo->prepare("DELETE FROM `nu_workflow_stages` WHERE `wfs_wf_id` = ?")->execute([$wfId]);

                $pdo->prepare("INSERT INTO `nu_workflow_stages` (
                    `wfs_id`, `wfs_wf_id`, `wfs_code`, `wfs_name`, `wfs_description`, `wfs_color`, `wfs_is_start`, `wfs_is_end`, `wfs_order`
                ) VALUES (101, ?, 'Pending', 'Pending Service', 'The service request is created and is waiting for assignment/commencement.', '#f59e0b', 1, 0, 1)")->execute([$wfId]);
                $stagePendingId = 101;

                $pdo->prepare("INSERT INTO `nu_workflow_stages` (
                    `wfs_id`, `wfs_wf_id`, `wfs_code`, `wfs_name`, `wfs_description`, `wfs_color`, `wfs_is_start`, `wfs_is_end`, `wfs_order`
                ) VALUES (102, ?, 'In Progress', 'Service In Progress', 'The staff has commenced providing the requested service.', '#3b82f6', 0, 0, 2)")->execute([$wfId]);
                $stageProgressId = 102;

                $pdo->prepare("INSERT INTO `nu_workflow_stages` (
                    `wfs_id`, `wfs_wf_id`, `wfs_code`, `wfs_name`, `wfs_description`, `wfs_color`, `wfs_is_start`, `wfs_is_end`, `wfs_order`
                ) VALUES (103, ?, 'Completed', 'Completed', 'The requested service has been successfully completed.', '#10b981', 0, 1, 3)")->execute([$wfId]);
                $stageCompletedId = 103;

                $pdo->prepare("INSERT INTO `nu_workflow_transitions` (
                    `wft_id`, `wft_wf_id`, `wft_from_id`, `wft_to_id`, `wft_action`, `wft_label`, `wft_hook`
                ) VALUES (101, ?, ?, ?, 'advance', 'Start Providing Service', 'update_record')")->execute([
                    $wfId, $stagePendingId, $stageProgressId
                ]);

                $pdo->prepare("INSERT INTO `nu_workflow_transitions` (
                    `wft_id`, `wft_wf_id`, `wft_from_id`, `wft_to_id`, `wft_action`, `wft_label`, `wft_hook`
                ) VALUES (102, ?, ?, ?, 'advance', 'Mark Service Completed', 'update_record')")->execute([
                    $wfId, $stageProgressId, $stageCompletedId
                ]);
            }
        }
    }
};
