<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasAgentsTable = false;
        try {
            $hasAgentsTable = (bool)$pdo->query($driver === 'sqlite' ? "SELECT name FROM sqlite_master WHERE type='table' AND name='nu_agents'" : "SHOW TABLES LIKE 'nu_agents'")->fetch();
        } catch (Exception $e) {}

        if (!$hasAgentsTable) {
            if ($driver === 'sqlite') {
                $pdo->exec("CREATE TABLE `nu_agents` (
                    `agent_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `agent_code` VARCHAR(100) NOT NULL UNIQUE,
                    `agent_name` VARCHAR(150) NOT NULL,
                    `agent_system_prompt` TEXT DEFAULT NULL,
                    `agent_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-1.5-flash',
                    `agent_tools` TEXT DEFAULT NULL,
                    `agent_tool_config` TEXT DEFAULT NULL,
                    `agent_memory_type` VARCHAR(50) NOT NULL DEFAULT 'conversation',
                    `agent_max_tokens` INTEGER NOT NULL DEFAULT 2000,
                    `agent_active` INTEGER NOT NULL DEFAULT 1,
                    `agent_created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                    `agent_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");

                $pdo->exec("CREATE TABLE `nu_agent_runs` (
                    `run_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `agent_id` INTEGER NOT NULL,
                    `run_status` VARCHAR(50) NOT NULL DEFAULT 'running',
                    `input_prompt` TEXT DEFAULT NULL,
                    `output_text` TEXT DEFAULT NULL,
                    `error_message` TEXT DEFAULT NULL,
                    `context` TEXT DEFAULT NULL,
                    `prompt_tokens` INTEGER DEFAULT 0,
                    `completion_tokens` INTEGER DEFAULT 0,
                    `total_tokens` INTEGER DEFAULT 0,
                    `started_at` TEXT DEFAULT NULL,
                    `completed_at` TEXT DEFAULT NULL,
                    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");

                $pdo->exec("CREATE TABLE `nu_agent_messages` (
                    `msg_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `run_id` INTEGER NOT NULL,
                    `msg_role` VARCHAR(50) NOT NULL,
                    `msg_content` TEXT DEFAULT NULL,
                    `msg_raw` TEXT DEFAULT NULL,
                    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");

                $pdo->exec("CREATE TABLE `nu_agent_tool_calls` (
                    `tool_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `run_id` INTEGER NOT NULL,
                    `tool_call_id` VARCHAR(100) NOT NULL,
                    `tool_name` VARCHAR(150) NOT NULL,
                    `tool_arguments` TEXT DEFAULT NULL,
                    `tool_result` TEXT DEFAULT NULL,
                    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");

                $pdo->exec("CREATE TABLE `nu_agent_memory` (
                    `mem_id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `agent_id` INTEGER NOT NULL,
                    `entity_key` VARCHAR(150) NOT NULL,
                    `mem_key` VARCHAR(150) NOT NULL,
                    `mem_value` TEXT DEFAULT NULL,
                    `mem_created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                    `mem_updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $pdo->exec("CREATE TABLE `nu_agents` (
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

                $pdo->exec("CREATE TABLE `nu_agent_runs` (
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

                $pdo->exec("CREATE TABLE `nu_agent_messages` (
                    `msg_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `run_id` INT UNSIGNED NOT NULL,
                    `msg_role` VARCHAR(50) NOT NULL,
                    `msg_content` LONGTEXT DEFAULT NULL,
                    `msg_raw` LONGTEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_msg_run` (`run_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE `nu_agent_tool_calls` (
                    `tool_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `run_id` INT UNSIGNED NOT NULL,
                    `tool_call_id` VARCHAR(100) NOT NULL,
                    `tool_name` VARCHAR(150) NOT NULL,
                    `tool_arguments` LONGTEXT DEFAULT NULL,
                    `tool_result` LONGTEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_tool_run` (`run_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE `nu_agent_memory` (
                    `mem_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `agent_id` INT UNSIGNED NOT NULL,
                    `entity_key` VARCHAR(150) NOT NULL,
                    `mem_key` VARCHAR(150) NOT NULL,
                    `mem_value` TEXT DEFAULT NULL,
                    `mem_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `mem_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_mem_agent_entity` (`agent_id`, `entity_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }

            // Seed default agent definition
            $defaultTools = json_encode(['query_records', 'get_record', 'create_record', 'update_record', 'run_procedure', 'send_email', 'call_webhook', 'start_workflow', 'advance_workflow']);
            $pdo->prepare("INSERT INTO `nu_agents` (`agent_code`, `agent_name`, `agent_system_prompt`, `agent_model`, `agent_tools`) VALUES (?, ?, ?, ?, ?)")
                ->execute([
                    'default_assistant',
                    'Customer Support & Service Assistant',
                    'You are a helpful Nuvis enterprise AI assistant. You process customer requests, query status details, run procedures, and manage workflow transitions.',
                    'gemini-1.5-flash',
                    $defaultTools
                ]);
        }
    }
};
