<?php
declare(strict_types=1);
/**
 * NuDatabase - PDO wrapper (singleton)
 * PHP 8.1+ compatible
 * IMPORTANT: destructor must NOT touch session - it runs during PHP shutdown
 * after session_write_close() has already been called.
 */
class NuDatabase {
    private static $instance = null;
    private $pdo;
    private $config;

    private function __construct() {
        global $nuConfig;
        if (empty($nuConfig)) {
            $configPath = defined('NU_ROOT') ? NU_ROOT . '/config.php' : dirname(__DIR__) . '/config.php';
            if (is_file($configPath)) {
                require_once $configPath;
            }
        }
        $this->config = $nuConfig ?? [];
        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function getConnection() {
        return self::getInstance()->pdo;
    }

    private function connect() {
        $dbType = $this->config['dbDriver'] ?? $this->config['dbType'] ?? 'mysql';
        $dbName = (string)($this->config['dbName'] ?? '');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ];

        if ($dbType === 'sqlite' || (str_ends_with($dbName, '.db') || str_ends_with($dbName, '.sqlite')) || (!empty($this->config['dbSqlitePath']) && is_file($this->config['dbSqlitePath']))) {
            $sqliteFile = !empty($this->config['dbSqlitePath']) ? $this->config['dbSqlitePath'] : $dbName;
            $dsn = "sqlite:" . $sqliteFile;
            $user = null;
            $pass = null;
        } else {
            $host    = $this->config['dbHost']     ?? 'localhost';
            $user    = $this->config['dbUser']     ?? '';
            $pass    = $this->config['dbPassword'] ?? '';
            $charset = $this->config['dbCharset']  ?? 'utf8mb4';
            $port    = $this->config['dbPort']     ?? 3306;
            $dsn     = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
        }

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }

        // Optimization: Skip self-healing DDL queries if schema version is already current in session
        $sessionActive = (session_status() === PHP_SESSION_ACTIVE);
        if ($sessionActive && ($_SESSION['_nu_schema_version'] ?? '') === NU_VERSION) {
            return;
        }

        // Ensure nu_menus columns exist in case of upgrade or existing database
        if (!$sessionActive || empty($_SESSION['_nu_menu_columns_ensured'])) {
            try {
                $tableExists = $this->pdo->query("SHOW TABLES LIKE 'nu_menus'")->fetch();
                if ($tableExists) {
                    $columns = [];
                    $stmt = $this->pdo->query("SHOW COLUMNS FROM `nu_menus`");
                    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $columns[] = strtolower($col['Field']);
                    }
                    if (!in_array('menu_role_access', $columns, true)) {
                        $this->pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_role_access` VARCHAR(512) DEFAULT NULL");
                    }
                    if (!in_array('menu_open_mode', $columns, true)) {
                        $this->pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_open_mode` VARCHAR(30) NOT NULL DEFAULT 'inline|browse'");
                    }
                    if (!in_array('menu_browse_mode', $columns, true)) {
                        $this->pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_browse_mode` VARCHAR(10) NOT NULL DEFAULT 'inline'");
                    }
                    if (!in_array('menu_preview_mode', $columns, true)) {
                        $this->pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_preview_mode` VARCHAR(10) NOT NULL DEFAULT 'inline'");
                    }
                    if (!in_array('menu_default_view', $columns, true)) {
                        $this->pdo->exec("ALTER TABLE `nu_menus` ADD COLUMN `menu_default_view` VARCHAR(10) NOT NULL DEFAULT 'browse'");
                    }
                    // Ensure menu_type column is VARCHAR(30) so 'module' works on MySQL ENUMs
                    try {
                        $this->pdo->exec("ALTER TABLE `nu_menus` MODIFY COLUMN `menu_type` VARCHAR(30) NOT NULL DEFAULT 'form'");
                    } catch (Exception $ex) {}
                    if ($sessionActive) {
                        $_SESSION['_nu_menu_columns_ensured'] = true;
                    }
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure AI Agent tables exist and are seeded
        if (!$sessionActive || empty($_SESSION['_nu_agent_tables_ensured'])) {
            try {
                $hasAgentsTable = $this->pdo->query("SHOW TABLES LIKE 'nu_agents'")->fetch();
                if (!$hasAgentsTable) {
                    $this->pdo->exec("CREATE TABLE `nu_agents` (
                        `agent_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `agent_code` VARCHAR(100) NOT NULL UNIQUE,
                        `agent_name` VARCHAR(150) NOT NULL,
                        `agent_system_prompt` TEXT DEFAULT NULL,
                        `agent_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-1.5-flash',
                        `agent_tools` TEXT DEFAULT NULL,
                        `agent_tool_config` TEXT DEFAULT NULL,
                        `agent_memory_type` VARCHAR(50) NOT NULL DEFAULT 'conversation',
                        `agent_max_tokens` INT NOT NULL DEFAULT 2000,
                        `agent_active` TINYINT(1) NOT NULL DEFAULT 1,
                        `agent_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `agent_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX `idx_agent_code` (`agent_code`),
                        INDEX `idx_agent_active` (`agent_active`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $this->pdo->exec("CREATE TABLE `nu_agent_runs` (
                        `run_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `agent_id` INT UNSIGNED NOT NULL,
                        `run_status` VARCHAR(50) NOT NULL DEFAULT 'running',
                        `input_prompt` TEXT DEFAULT NULL,
                        `output_text` LONGTEXT DEFAULT NULL,
                        `error_message` TEXT DEFAULT NULL,
                        `context` TEXT DEFAULT NULL,
                        `prompt_tokens` INT DEFAULT 0,
                        `completion_tokens` INT DEFAULT 0,
                        `total_tokens` INT DEFAULT 0,
                        `started_at` DATETIME DEFAULT NULL,
                        `completed_at` DATETIME DEFAULT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_run_agent` (`agent_id`),
                        INDEX `idx_run_status` (`run_status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $this->pdo->exec("CREATE TABLE `nu_agent_messages` (
                        `msg_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `run_id` INT UNSIGNED NOT NULL,
                        `msg_role` VARCHAR(50) NOT NULL,
                        `msg_content` LONGTEXT DEFAULT NULL,
                        `msg_raw` LONGTEXT DEFAULT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_msg_run` (`run_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $this->pdo->exec("CREATE TABLE `nu_agent_tool_calls` (
                        `tool_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `run_id` INT UNSIGNED NOT NULL,
                        `tool_call_id` VARCHAR(100) NOT NULL,
                        `tool_name` VARCHAR(150) NOT NULL,
                        `tool_arguments` LONGTEXT DEFAULT NULL,
                        `tool_result` LONGTEXT DEFAULT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_tool_run` (`run_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $this->pdo->exec("CREATE TABLE `nu_agent_memory` (
                        `mem_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `agent_id` INT UNSIGNED NOT NULL,
                        `entity_key` VARCHAR(150) NOT NULL,
                        `mem_key` VARCHAR(150) NOT NULL,
                        `mem_value` TEXT DEFAULT NULL,
                        `mem_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `mem_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX `idx_mem_agent_entity` (`agent_id`, `entity_key`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    // Seed default agent definition
                    $defaultTools = json_encode(['query_records', 'get_record', 'create_record', 'update_record', 'run_procedure', 'send_email', 'call_webhook', 'start_workflow', 'advance_workflow']);
                    $this->pdo->prepare("INSERT INTO `nu_agents` (`agent_code`, `agent_name`, `agent_system_prompt`, `agent_model`, `agent_tools`) VALUES (?, ?, ?, ?, ?)")
                        ->execute([
                            'default_assistant',
                            'Customer Support & Service Assistant',
                            'You are a helpful Nuvis enterprise AI assistant. You process customer requests, query status details, run procedures, and manage workflow transitions.',
                            'gemini-1.5-flash',
                            $defaultTools
                        ]);
                }
                if ($sessionActive) {
                    $_SESSION['_nu_agent_tables_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure nu_password_policy and nu_password_history exist
        if (!$sessionActive || empty($_SESSION['_nu_password_tables_ensured'])) {
            try {
                $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $hasPolicy = false;
                try {
                    $hasPolicy = (bool)$this->pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_password_policy'" : "SHOW TABLES LIKE 'nu_password_policy'")->fetch();
                } catch (Exception $e) {}

                if (!$hasPolicy) {
                    if ($driver === 'sqlite') {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_policy` (
                            `policy_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `policy_min_length` INTEGER NOT NULL DEFAULT 8,
                            `policy_require_uppercase` INTEGER NOT NULL DEFAULT 1,
                            `policy_require_lowercase` INTEGER NOT NULL DEFAULT 1,
                            `policy_require_number` INTEGER NOT NULL DEFAULT 1,
                            `policy_require_special` INTEGER NOT NULL DEFAULT 0,
                            `policy_disallow_username` INTEGER NOT NULL DEFAULT 1,
                            `policy_history_count` INTEGER NOT NULL DEFAULT 5,
                            `policy_expiry_days` INTEGER NOT NULL DEFAULT 0,
                            `policy_expiry_warning_days` INTEGER NOT NULL DEFAULT 7,
                            `policy_force_change_on_first_login` INTEGER NOT NULL DEFAULT 1,
                            `policy_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                        )");
                        $this->pdo->exec("INSERT OR IGNORE INTO `nu_password_policy` (`policy_id`) VALUES (1)");
                    } else {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_policy` (
                            `policy_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            `policy_min_length` INT NOT NULL DEFAULT 8,
                            `policy_require_uppercase` TINYINT(1) NOT NULL DEFAULT 1,
                            `policy_require_lowercase` TINYINT(1) NOT NULL DEFAULT 1,
                            `policy_require_number` TINYINT(1) NOT NULL DEFAULT 1,
                            `policy_require_special` TINYINT(1) NOT NULL DEFAULT 0,
                            `policy_disallow_username` TINYINT(1) NOT NULL DEFAULT 1,
                            `policy_history_count` INT NOT NULL DEFAULT 5,
                            `policy_expiry_days` INT NOT NULL DEFAULT 0,
                            `policy_expiry_warning_days` INT NOT NULL DEFAULT 7,
                            `policy_force_change_on_first_login` TINYINT(1) NOT NULL DEFAULT 1,
                            `policy_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        $this->pdo->exec("INSERT IGNORE INTO `nu_password_policy` (`policy_id`) VALUES (1)");
                    }
                }

                $hasHistory = false;
                try {
                    $hasHistory = (bool)$this->pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_password_history'" : "SHOW TABLES LIKE 'nu_password_history'")->fetch();
                } catch (Exception $e) {}

                if (!$hasHistory) {
                    if ($driver === 'sqlite') {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_history` (
                            `ph_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `ph_user_id` TEXT NOT NULL,
                            `ph_hash` TEXT NOT NULL,
                            `ph_created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                        )");
                    } else {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_password_history` (
                            `ph_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            `ph_user_id` VARCHAR(64) NOT NULL,
                            `ph_hash` VARCHAR(255) NOT NULL,
                            `ph_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_ph_user` (`ph_user_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    }
                }

                if ($sessionActive) {
                    $_SESSION['_nu_password_tables_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure nu_system_settings table exists and is populated
        if (!$sessionActive || empty($_SESSION['_nu_system_settings_ensured'])) {
            try {
                $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $hasSys = false;
                try {
                    $hasSys = (bool)$this->pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_system_settings'" : "SHOW TABLES LIKE 'nu_system_settings'")->fetch();
                } catch (Exception $e) {}

                if (!$hasSys) {
                    $this->pdo->exec("CREATE TABLE `nu_system_settings` (
                        `setting_key` VARCHAR(50) NOT NULL,
                        `setting_value` LONGTEXT DEFAULT NULL,
                        PRIMARY KEY (`setting_key`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                }

                // Seed default settings if they are missing
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `nu_system_settings` WHERE `setting_key` = ?");

                // app_name
                $stmt->execute(['app_name']);
                if ((int)$stmt->fetchColumn() === 0) {
                    $this->pdo->prepare("INSERT INTO `nu_system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)")
                              ->execute(['app_name', 'nuvis']);
                }

                // app_logo
                $stmt->execute(['app_logo']);
                if ((int)$stmt->fetchColumn() === 0) {
                    $this->pdo->prepare("INSERT INTO `nu_system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)")
                              ->execute(['app_logo', '']);
                }

                // system_fields_def
                $stmt->execute(['system_fields_def']);
                if ((int)$stmt->fetchColumn() === 0) {
                    $this->pdo->prepare("INSERT INTO `nu_system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)")
                              ->execute(['system_fields_def', '[]']);
                }

                // forgot_password_enabled
                $stmt->execute(['forgot_password_enabled']);
                if ((int)$stmt->fetchColumn() === 0) {
                    $this->pdo->prepare("INSERT INTO `nu_system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)")
                              ->execute(['forgot_password_enabled', '1']);
                }

                // user_header_format
                $stmt->execute(['user_header_format']);
                if ((int)$stmt->fetchColumn() === 0) {
                    $this->pdo->prepare("INSERT INTO `nu_system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)")
                              ->execute(['user_header_format', '{name} | {location}']);
                }

                if ($sessionActive) {
                    $_SESSION['_nu_system_settings_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure API Manager (nu_api_endpoints and nu_api_logs) tables exist
        if (!$sessionActive || empty($_SESSION['_nu_api_manager_tables_ensured'])) {
            try {
                $hasEndpoints = $this->pdo->query("SHOW TABLES LIKE 'nu_api_endpoints'")->fetch();
                if (!$hasEndpoints) {
                    $this->pdo->exec("CREATE TABLE `nu_api_endpoints` (
                        `endpoint_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `endpoint_name` VARCHAR(150) NOT NULL,
                        `endpoint_route` VARCHAR(250) NOT NULL UNIQUE,
                        `endpoint_method` VARCHAR(10) NOT NULL DEFAULT 'GET',
                        `endpoint_type` VARCHAR(20) NOT NULL DEFAULT 'form',
                        `endpoint_target` VARCHAR(200) NOT NULL,
                        `endpoint_active` TINYINT(1) NOT NULL DEFAULT 1,
                        `endpoint_config` TEXT DEFAULT NULL,
                        `endpoint_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_api_route` (`endpoint_route`),
                        INDEX `idx_api_active` (`endpoint_active`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                }

                $hasLogs = $this->pdo->query("SHOW TABLES LIKE 'nu_api_logs'")->fetch();
                if (!$hasLogs) {
                    $this->pdo->exec("CREATE TABLE `nu_api_logs` (
                        `log_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `log_route` VARCHAR(250) NOT NULL,
                        `log_method` VARCHAR(10) NOT NULL,
                        `log_token_name` VARCHAR(100) DEFAULT NULL,
                        `log_user_id` VARCHAR(64) DEFAULT NULL,
                        `log_request_payload` TEXT DEFAULT NULL,
                        `log_response_code` INT NOT NULL,
                        `log_response_payload` TEXT DEFAULT NULL,
                        `log_duration` DOUBLE DEFAULT NULL,
                        `log_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_log_route` (`log_route`),
                        INDEX `idx_log_created` (`log_created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                }

                if ($sessionActive) {
                    $_SESSION['_nu_api_manager_tables_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure nu_procedures table exists
        if (!$sessionActive || empty($_SESSION['_nu_procedures_ensured'])) {
            try {
                $hasProcedures = $this->pdo->query("SHOW TABLES LIKE 'nu_procedures'")->fetch();
                if (!$hasProcedures) {
                    $this->pdo->exec("CREATE TABLE `nu_procedures` (
                        `procedure_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `procedure_name` VARCHAR(150) NOT NULL,
                        `procedure_code` VARCHAR(100) NOT NULL UNIQUE,
                        `procedure_description` VARCHAR(255) DEFAULT NULL,
                        `procedure_php` MEDIUMTEXT DEFAULT NULL,
                        `procedure_active` TINYINT(1) NOT NULL DEFAULT 1,
                        `procedure_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `procedure_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX `idx_proc_code` (`procedure_code`),
                        INDEX `idx_proc_active` (`procedure_active`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                }
                if ($sessionActive) {
                    $_SESSION['_nu_procedures_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure nu_products table exists and is populated
        if (!$sessionActive || empty($_SESSION['_nu_products_ensured'])) {
            try {
                $hasProducts = $this->pdo->query("SHOW TABLES LIKE 'nu_products'")->fetch();
                if (!$hasProducts) {
                    $this->pdo->exec("CREATE TABLE `nu_products` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `name` VARCHAR(150) NOT NULL,
                        `type` VARCHAR(50) NOT NULL DEFAULT 'product',
                        `barcode` VARCHAR(50) NOT NULL UNIQUE,
                        `description` TEXT DEFAULT NULL,
                        `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                        INDEX `idx_product_barcode` (`barcode`),
                        INDEX `idx_product_type` (`type`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    // Seed initial products, goods, and services
                    $this->pdo->exec("INSERT INTO `nu_products` (`name`, `type`, `barcode`, `description`, `price`) VALUES
                    ('Organic Coffee Beans (Dark Roast)', 'product', '7501031311309', 'Premium single-origin organic arabica coffee beans, slow-roasted to a rich dark finish. 500g bag.', 18.99),
                    ('Ergonomic Wireless Mouse', 'product', '074182285197', 'Rechargeable wireless ergonomic mouse with silent clicking, side-scroll wheel, and adjustable DPI settings up to 4000.', 45.50),
                    ('Eco-Friendly Bamboo Water Bottle', 'product', '8886367301031', 'Double-walled vacuum insulated water bottle made from stainless steel and covered with natural, sustainable bamboo. 750ml.', 24.99),
                    ('Heavy Duty Industrial Pallet', 'good', 'GD-WRH-PAL-02', 'High-density polyethylene structural foam plastic pallet. Ideal for warehouse storage and forklift transport. Rated up to 1500kg.', 79.95),
                    ('Ultra-Soft Microfiber Towel Set', 'good', 'GD-TOWEL-S4', 'Pack of 4 quick-drying, highly absorbent premium microfiber towels. Perfect for home, gym, or automotive detailing.', 15.49),
                    ('Web Application Development Consultation', 'service', 'SVC-WEB-DEV-01', 'One-hour professional architectural and consulting session with a Senior Software Engineer regarding web app stacks and cloud hosting.', 120.00),
                    ('Enterprise IT Security Audit', 'service', 'SVC-IT-SEC-05', 'Comprehensive end-to-end network penetration testing, software vulnerability scan, and infrastructure compliance audit report.', 1500.00)");
                }
                if ($sessionActive) {
                    $_SESSION['_nu_products_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure developer demo tables exist and are populated
        if (!$sessionActive || empty($_SESSION['_nu_demo_tables_ensured'])) {
            try {
                $hasDemoTypes = $this->pdo->query("SHOW TABLES LIKE 'demo_service_types'")->fetch();
                if (!$hasDemoTypes) {
                    $this->pdo->exec("CREATE TABLE IF NOT EXISTS `demo_service_types` (
                        `service_type_id` VARCHAR(36) NOT NULL,
                        `name` VARCHAR(150) NOT NULL,
                        `description` TEXT DEFAULT NULL,
                        `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                        PRIMARY KEY (`service_type_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                    $this->pdo->exec("INSERT INTO `demo_service_types` (`service_type_id`, `name`, `description`, `price`) VALUES
                    ('a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d', 'Plumbing Maintenance', 'General plumbing repairs, leak detection, and pipe maintenance.', 150.00),
                    ('b2c3d4e5-f6a7-8b9c-0d1e-2f3a4b5c6d7e', 'Electrical Inspection', 'Safety audit of wiring, outlets, and panel inspection.', 120.00),
                    ('c3d4e5f6-a7b8-9c0d-1e2f-3a4b5c6d7e8f', 'HVAC System Service', 'Heating, ventilation, and air conditioning diagnostic and filter change.', 200.00)");
                }

                $hasDemoRequests = $this->pdo->query("SHOW TABLES LIKE 'demo_customer_requests'")->fetch();
                if (!$hasDemoRequests) {
                    $this->pdo->exec("CREATE TABLE IF NOT EXISTS `demo_customer_requests` (
                        `request_id` VARCHAR(36) NOT NULL,
                        `customer_name` VARCHAR(150) NOT NULL,
                        `service_type_id` VARCHAR(36) NOT NULL,
                        `request_details` TEXT DEFAULT NULL,
                        `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`request_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                    $this->pdo->exec("INSERT INTO `demo_customer_requests` (`request_id`, `customer_name`, `service_type_id`, `request_details`, `status`) VALUES
                    ('d4e5f6a7-b8c9-0d1e-2f3a-4b5c6d7e8f9a', 'Alice Smith', 'a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d', 'Main drain is running extremely slow and backing up.', 'Pending'),
                    ('e5f6a7b8-c9d0-1e2f-3a4b-5c6d7e8f9a0b', 'Bob Johnson', 'b2c3d4e5-f6a7-8b9c-0d1e-2f3a4b5c6d7e', 'Living room outlets have no power. Breaker is not tripped.', 'Pending')");
                }

                $hasDemoStaff = $this->pdo->query("SHOW TABLES LIKE 'demo_staff_services'")->fetch();
                if (!$hasDemoStaff) {
                    $this->pdo->exec("CREATE TABLE IF NOT EXISTS `demo_staff_services` (
                        `service_log_id` VARCHAR(36) NOT NULL,
                        `customer_request_id` VARCHAR(36) NOT NULL,
                        `staff_notes` TEXT DEFAULT NULL,
                        `service_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`service_log_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                if ($sessionActive) {
                    $_SESSION['_nu_demo_tables_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure developer demo forms exist in nu_forms
        if (!$sessionActive || empty($_SESSION['_nu_demo_forms_ensured'])) {
            try {
                $hasForm = $this->pdo->query("SELECT form_id FROM nu_forms WHERE form_code = 'demo_customer_requests'")->fetch();

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
                    // 1. Service Types Form
                    $this->pdo->prepare("INSERT INTO `nu_forms` (
                        `form_code`, `form_type`, `form_name`, `form_table`, `form_description`,
                        `form_layout`, `browse_layout`, `form_active`, `form_pk_type`, `form_table_mode`
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                        'demo_service_types', 'main', 'Service Types', 'demo_service_types', 'Manage service types and pricing.',
                        $layoutTypes, $browseTypes, 1, 'uuid', 'existing'
                    ]);

                    // 2. Customer Requests Form
                    $this->pdo->prepare("INSERT INTO `nu_forms` (
                        `form_code`, `form_type`, `form_name`, `form_table`, `form_description`,
                        `form_layout`, `browse_layout`, `form_active`, `form_pk_type`, `form_table_mode`
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                        'demo_customer_requests', 'main', 'Customer Requests', 'demo_customer_requests', 'Manage customer requests.',
                        $layoutRequests, $browseRequests, 1, 'uuid', 'existing'
                    ]);

                    // 3. Staff Services Form
                    $this->pdo->prepare("INSERT INTO `nu_forms` (
                        `form_code`, `form_type`, `form_name`, `form_table`, `form_description`,
                        `form_layout`, `browse_layout`, `form_custom_php_after`, `form_active`, `form_pk_type`, `form_table_mode`
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                        'demo_staff_services', 'main', 'Staff Services', 'demo_staff_services', 'Log staff service actions.',
                        $layoutStaff, $browseStaff, $staffAfterSave, 1, 'uuid', 'existing'
                    ]);
                } else {
                    // Update existing form layouts to ensure Join configs are in place
                    $this->pdo->prepare("UPDATE `nu_forms` SET `form_layout` = ?, `browse_layout` = ? WHERE `form_code` = ?")->execute([$layoutTypes, $browseTypes, 'demo_service_types']);
                    $this->pdo->prepare("UPDATE `nu_forms` SET `form_layout` = ?, `browse_layout` = ? WHERE `form_code` = ?")->execute([$layoutRequests, $browseRequests, 'demo_customer_requests']);
                    $this->pdo->prepare("UPDATE `nu_forms` SET `form_layout` = ?, `browse_layout` = ?, `form_custom_php_after` = ? WHERE `form_code` = ?")->execute([$layoutStaff, $browseStaff, $staffAfterSave, 'demo_staff_services']);
                }

                if ($sessionActive) {
                    $_SESSION['_nu_demo_forms_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure developer demo workflow exists in nu_workflows
        if (!$sessionActive || empty($_SESSION['_nu_demo_workflow_ensured'])) {
            try {
                $hasWf = $this->pdo->query("SELECT wf_id FROM nu_workflows WHERE wf_code = 'customer_request_wf'")->fetch();
                if (!$hasWf) {
                    // 1. Insert workflow
                    $this->pdo->prepare("INSERT INTO `nu_workflows` (
                        `wf_code`, `wf_name`, `wf_description`, `wf_form_code`, `wf_active`
                    ) VALUES (?, ?, ?, ?, ?)")->execute([
                        'customer_request_wf', 'Customer Request Workflow', 'A demo workflow tracking customer service requests through life cycle stage progressions.', 'demo_customer_requests', 1
                    ]);
                    $wfId = (int)$this->pdo->lastInsertId();
                } else {
                    $wfId = (int)$hasWf['wf_id'];
                }

                // Verify and synchronize stages
                $stageStmt = $this->pdo->prepare("SELECT wfs_id, wfs_code FROM nu_workflow_stages WHERE wfs_wf_id = ?");
                $stageStmt->execute([$wfId]);
                $existingStages = $stageStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

                if (empty($existingStages) || !isset($existingStages['Pending']) || !isset($existingStages['In Progress']) || !isset($existingStages['Completed'])) {
                    // Wipe broken structures for this workflow and re-insert them cleanly with explicit IDs matching the seeded instances
                    $this->pdo->prepare("DELETE FROM `nu_workflow_transitions` WHERE `wft_wf_id` = ?")->execute([$wfId]);
                    $this->pdo->prepare("DELETE FROM `nu_workflow_stages` WHERE `wfs_wf_id` = ?")->execute([$wfId]);

                    // Stage 1: Pending
                    $this->pdo->prepare("INSERT INTO `nu_workflow_stages` (
                        `wfs_id`, `wfs_wf_id`, `wfs_code`, `wfs_name`, `wfs_description`, `wfs_color`, `wfs_is_start`, `wfs_is_end`, `wfs_order`
                    ) VALUES (101, ?, 'Pending', 'Pending Service', 'The service request is created and is waiting for assignment/commencement.', '#f59e0b', 1, 0, 1)")->execute([$wfId]);
                    $stagePendingId = 101;

                    // Stage 2: In Progress
                    $this->pdo->prepare("INSERT INTO `nu_workflow_stages` (
                        `wfs_id`, `wfs_wf_id`, `wfs_code`, `wfs_name`, `wfs_description`, `wfs_color`, `wfs_is_start`, `wfs_is_end`, `wfs_order`
                    ) VALUES (102, ?, 'In Progress', 'Service In Progress', 'The staff has commenced providing the requested service.', '#3b82f6', 0, 0, 2)")->execute([$wfId]);
                    $stageProgressId = 102;

                    // Stage 3: Completed
                    $this->pdo->prepare("INSERT INTO `nu_workflow_stages` (
                        `wfs_id`, `wfs_wf_id`, `wfs_code`, `wfs_name`, `wfs_description`, `wfs_color`, `wfs_is_start`, `wfs_is_end`, `wfs_order`
                    ) VALUES (103, ?, 'Completed', 'Completed', 'The requested service has been successfully completed.', '#10b981', 0, 1, 3)")->execute([$wfId]);
                    $stageCompletedId = 103;

                    // Insert Transitions & Action Hooks with explicit transition IDs 101, 102
                    // Transition 1: Start Service (Pending -> In Progress)
                    $this->pdo->prepare("INSERT INTO `nu_workflow_transitions` (
                        `wft_id`, `wft_wf_id`, `wft_from_id`, `wft_to_id`, `wft_action`, `wft_label`, `wft_hook`
                    ) VALUES (101, ?, ?, ?, 'advance', 'Start Providing Service', 'update_record')")->execute([
                        $wfId, $stagePendingId, $stageProgressId
                    ]);

                    // Transition 2: Finish Service (In Progress -> Completed)
                    $this->pdo->prepare("INSERT INTO `nu_workflow_transitions` (
                        `wft_id`, `wft_wf_id`, `wft_from_id`, `wft_to_id`, `wft_action`, `wft_label`, `wft_hook`
                    ) VALUES (102, ?, ?, ?, 'advance', 'Mark Service Completed', 'update_record')")->execute([
                        $wfId, $stageProgressId, $stageCompletedId
                    ]);
                } else {
                    // Double check transitions exist with exact IDs
                    $transStmt = $this->pdo->prepare("SELECT COUNT(*) FROM nu_workflow_transitions WHERE wft_wf_id = ?");
                    $transStmt->execute([$wfId]);
                    if ((int)$transStmt->fetchColumn() === 0) {
                        $stagePendingId   = (int)$existingStages['Pending'];
                        $stageProgressId  = (int)$existingStages['In Progress'];
                        $stageCompletedId = (int)$existingStages['Completed'];

                        // Re-insert Transitions & Action Hooks with explicit IDs
                        $this->pdo->prepare("INSERT INTO `nu_workflow_transitions` (
                            `wft_id`, `wft_wf_id`, `wft_from_id`, `wft_to_id`, `wft_action`, `wft_label`, `wft_hook`
                        ) VALUES (101, ?, ?, ?, 'advance', 'Start Providing Service', 'update_record')")->execute([
                            $wfId, $stagePendingId, $stageProgressId
                        ]);

                        $this->pdo->prepare("INSERT INTO `nu_workflow_transitions` (
                            `wft_id`, `wft_wf_id`, `wft_from_id`, `wft_to_id`, `wft_action`, `wft_label`, `wft_hook`
                        ) VALUES (102, ?, ?, ?, 'advance', 'Mark Service Completed', 'update_record')")->execute([
                            $wfId, $stageProgressId, $stageCompletedId
                        ]);
                    }
                }

                if ($sessionActive) {
                    $_SESSION['_nu_demo_workflow_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        // Self-healing: Ensure Multi-Project tables & columns exist
        if (!$sessionActive || empty($_SESSION['_nu_projects_ensured'])) {
            try {
                $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $hasProjects = false;
                try {
                    $hasProjects = (bool)$this->pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_projects'" : "SHOW TABLES LIKE 'nu_projects'")->fetch();
                } catch (Exception $e) {}

                if (!$hasProjects) {
                    if ($driver === 'sqlite') {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_projects` (
                            `project_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `project_code` TEXT NOT NULL UNIQUE,
                            `project_name` TEXT NOT NULL,
                            `project_description` TEXT NULL,
                            `project_settings` TEXT NULL,
                            `project_active` INTEGER NOT NULL DEFAULT 1,
                            `project_is_default` INTEGER NOT NULL DEFAULT 0,
                            `project_owner_id` INTEGER NULL,
                            `project_created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                            `project_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                        )");
                    } else {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_projects` (
                            `project_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            `project_code` VARCHAR(50) NOT NULL,
                            `project_name` VARCHAR(150) NOT NULL,
                            `project_description` TEXT NULL,
                            `project_settings` LONGTEXT NULL,
                            `project_active` TINYINT(1) NOT NULL DEFAULT 1,
                            `project_is_default` TINYINT(1) NOT NULL DEFAULT 0,
                            `project_owner_id` INT NULL,
                            `project_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            `project_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            PRIMARY KEY (`project_id`),
                            UNIQUE KEY `uq_project_code` (`project_code`),
                            KEY `idx_project_active` (`project_active`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    }
                }

                // Ensure nu_project_versions table
                $hasProjectVersions = false;
                try {
                    $hasProjectVersions = (bool)$this->pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_project_versions'" : "SHOW TABLES LIKE 'nu_project_versions'")->fetch();
                } catch (Exception $e) {}

                if (!$hasProjectVersions) {
                    if ($driver === 'sqlite') {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_versions` (
                            `pv_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `pv_project_id` INTEGER NOT NULL,
                            `pv_version_tag` TEXT NOT NULL,
                            `pv_description` TEXT NULL,
                            `pv_snapshot_data` TEXT NOT NULL,
                            `pv_created_by` INTEGER NULL,
                            `pv_created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                        )");
                    } else {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_versions` (
                            `pv_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            `pv_project_id` INT UNSIGNED NOT NULL,
                            `pv_version_tag` VARCHAR(50) NOT NULL,
                            `pv_description` TEXT NULL,
                            `pv_snapshot_data` MEDIUMTEXT NOT NULL,
                            `pv_created_by` INT NULL,
                            `pv_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (`pv_id`),
                            KEY `idx_pv_project` (`pv_project_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    }
                }

                // Seed default project
                $stmtProj = $this->pdo->prepare("SELECT COUNT(*) FROM `nu_projects` WHERE `project_code` = 'default'");
                $stmtProj->execute();
                if ((int)$stmtProj->fetchColumn() === 0) {
                    $this->pdo->prepare("INSERT INTO `nu_projects` (`project_code`, `project_name`, `project_description`, `project_active`, `project_is_default`) VALUES ('default', 'Default Project', 'Default system project created during migration.', 1, 1)")->execute();
                }

                // Ensure nu_project_members table
                $hasMembers = false;
                try {
                    $hasMembers = (bool)$this->pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_project_members'" : "SHOW TABLES LIKE 'nu_project_members'")->fetch();
                } catch (Exception $e) {}

                if (!$hasMembers) {
                    if ($driver === 'sqlite') {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_members` (
                            `pm_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `pm_project_id` INTEGER NOT NULL,
                            `pm_user_id` INTEGER NOT NULL,
                            `pm_role` TEXT NOT NULL DEFAULT 'member'
                        )");
                    } else {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_members` (
                            `pm_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            `pm_project_id` INT UNSIGNED NOT NULL,
                            `pm_user_id` INT NOT NULL,
                            `pm_role` VARCHAR(30) NOT NULL DEFAULT 'member',
                            PRIMARY KEY (`pm_id`),
                            UNIQUE KEY `uq_project_user` (`pm_project_id`, `pm_user_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    }
                }

                // Ensure nu_project_tables registry table
                $hasProjectTables = false;
                try {
                    $hasProjectTables = (bool)$this->pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_project_tables'" : "SHOW TABLES LIKE 'nu_project_tables'")->fetch();
                } catch (Exception $e) {}

                if (!$hasProjectTables) {
                    if ($driver === 'sqlite') {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_tables` (
                            `pt_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                            `project_id` INTEGER NOT NULL,
                            `table_name` TEXT NOT NULL UNIQUE,
                            `form_code` TEXT NULL
                        )");
                    } else {
                        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `nu_project_tables` (
                            `pt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            `project_id` INT UNSIGNED NOT NULL,
                            `table_name` VARCHAR(64) NOT NULL,
                            `form_code` VARCHAR(50) NULL,
                            PRIMARY KEY (`pt_id`),
                            UNIQUE KEY `uq_pt_table` (`table_name`),
                            KEY `idx_pt_project` (`project_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    }
                }

                // Ensure project_id column on metadata tables
                $metaTables = ['nu_forms', 'nu_reports', 'nu_queries', 'nu_procedures', 'nu_menus', 'nu_workflows', 'nu_workflow_stages', 'nu_workflow_transitions', 'nu_form_versions'];
                foreach ($metaTables as $mTable) {
                    try {
                        $hasTbl = false;
                        if ($driver === 'sqlite') {
                            $hasTbl = (bool)$this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$mTable}'")->fetch();
                        } else {
                            $hasTbl = (bool)$this->pdo->query("SHOW TABLES LIKE '{$mTable}'")->fetch();
                        }

                        if (!$hasTbl) {
                            continue;
                        }

                        $hasCol = false;
                        if ($driver === 'sqlite') {
                            $cols = $this->pdo->query("PRAGMA table_info(`{$mTable}`)")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($cols as $c) {
                                if (($c['name'] ?? '') === 'project_id') { $hasCol = true; break; }
                            }
                        } else {
                            $cols = $this->pdo->query("DESCRIBE `{$mTable}`")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($cols as $c) {
                                if (($c['Field'] ?? '') === 'project_id') { $hasCol = true; break; }
                            }
                        }

                        if (!$hasCol) {
                            if ($driver === 'sqlite') {
                                $this->pdo->exec("ALTER TABLE `{$mTable}` ADD COLUMN `project_id` INTEGER DEFAULT 1");
                            } else {
                                $this->pdo->exec("ALTER TABLE `{$mTable}` ADD COLUMN `project_id` INT UNSIGNED NOT NULL DEFAULT 1");
                                try {
                                    $this->pdo->exec("ALTER TABLE `{$mTable}` ADD KEY `idx_{$mTable}_project` (`project_id`)");
                                } catch (Exception $e) {}
                            }
                        }
                        $this->pdo->exec("UPDATE `{$mTable}` SET `project_id` = 1 WHERE `project_id` IS NULL OR `project_id` = 0");
                    } catch (Exception $e) {}
                }

                if ($sessionActive) {
                    $_SESSION['_nu_projects_ensured'] = true;
                }
            } catch (Exception $ignored) {}
        }

        if ($sessionActive) {
            $_SESSION['_nu_schema_version'] = NU_VERSION;
        }
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function getDriverName(): string {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Run a DML query (SELECT / INSERT / UPDATE / DELETE) via a prepared statement.
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Run a DDL statement (CREATE TABLE, ALTER TABLE, DROP TABLE, etc.)
     * directly via PDO::exec() — bypasses the prepared-statement layer.
     * MySQL cannot run DDL through native prepared statements.
     * Throws PDOException on failure.
     */
    public function exec(string $sql) {
        $result = $this->pdo->exec($sql);
        if ($result === false) {
            $err = $this->pdo->errorInfo();
            throw new \PDOException('DDL exec failed [' . ($err[0] ?? '') . ']: ' . ($err[2] ?? 'unknown error'));
        }
        return $result;
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    // PHP 8.1+ compatible
    public function insert($table, $data) {
        $cols         = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(function($k) { return ":$k"; }, array_keys($data)));
        $params       = [];
        foreach ($data as $k => $v) {
            $params[":$k"] = $v;
        }
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
        $this->query($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    // PHP 8.1+ compatible
    public function update($table, $data, $where, $whereParams = []) {
        $sets   = implode(', ', array_map(function($k) { return "{$k} = :set_{$k}"; }, array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params[":set_{$k}"] = $v;
        }

        if (!empty($whereParams) && array_keys($whereParams) === range(0, count($whereParams) - 1)) {
            $i = 0;
            $where = preg_replace_callback('/\?/', function() use (&$i) {
                return ':where_' . $i++;
            }, $where);
            $namedWhere = [];
            foreach ($whereParams as $idx => $val) {
                $namedWhere[':where_' . $idx] = $val;
            }
            $whereParams = $namedWhere;
        }

        $sql  = "UPDATE {$table} SET {$sets} WHERE {$where}";
        $stmt = $this->query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    public function delete($table, $where, $params = []) {
        return $this->query("DELETE FROM {$table} WHERE {$where}", $params)->rowCount();
    }

    public function lastInsertId() {
        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction() { $this->pdo->beginTransaction(); }
    public function commit()           { $this->pdo->commit(); }
    public function rollback()         { $this->pdo->rollBack(); }

    public function __destruct() {
        $this->pdo = null;
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize NuDatabase.'); }
}

if (!class_exists('Database')) {
    class_alias('NuDatabase', 'Database');
}

/**
 * NuProcedure Helper Class
 * Allows executing saved custom PHP functions from other server-side contexts.
 */
class NuProcedure {
    /**
     * Executes a saved custom PHP function (procedure) by its code.
     *
     * @param string $code
     * @param array $params
     * @param array $hashCookies
     * @return array
     */
    public static function run($code, $params = [], $hashCookies = []) {
        $db = NuDatabase::getInstance();
        $proc = $db->fetchOne("SELECT * FROM nu_procedures WHERE procedure_code = ? AND procedure_active = 1", [$code]);
        if (!$proc) {
            return [
                'success' => false,
                'error' => "Procedure not found or is inactive: " . $code
            ];
        }

        // Setup sandboxed execution variables
        $_proc_params = $params;
        $_proc_db     = $db;
        $_proc_auth   = class_exists('NuAuth') ? NuAuth::getInstance() : null;
        $_proc_hash   = $hashCookies;
        $_proc_result = null;

        ob_start();
        try {
            eval('?>' . $proc['procedure_php']);
            $output = ob_get_clean();
            return [
                'success' => true,
                'output'  => $output,
                'data'    => $_proc_result
            ];
        } catch (Throwable $e) {
            ob_end_clean();
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }
}

if (!function_exists('nu_run_procedure')) {
    function nu_run_procedure($code, $params = [], $hashCookies = []) {
        return NuProcedure::run($code, $params, $hashCookies);
    }
}

if (!function_exists('run_procedure')) {
    function run_procedure($code, $params = [], $hashCookies = []) {
        return NuProcedure::run($code, $params, $hashCookies);
    }
}
