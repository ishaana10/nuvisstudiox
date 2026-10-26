<?php
declare(strict_types=1);
if (PHP_SAPI !== "cli") { http_response_code(403); exit("CLI execution only\n"); }

/**
 * CLI Scheduled Triggers & Cron Processor
 * Usage: php cli/cron.php
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Workflow.php';
require_once __DIR__ . '/../core/AgentRuntime.php';

$db = NuDatabase::getInstance();

echo "[" . date('Y-m-d H:i:s') . "] Starting Nuvis Scheduled Triggers Cron Processor...\n";

// Ensure nu_scheduled_triggers table exists
try {
    $pdo = $db->getPdo();
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_scheduled_triggers` (
            `st_id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `st_name` TEXT NOT NULL,
            `st_type` TEXT NOT NULL DEFAULT 'workflow',
            `st_target_id` TEXT NOT NULL,
            `st_cron_expression` TEXT,
            `st_next_run_at` DATETIME,
            `st_last_run_at` DATETIME,
            `st_status` TEXT DEFAULT 'active',
            `st_config` TEXT,
            `st_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_scheduled_triggers` (
            `st_id` INT AUTO_INCREMENT PRIMARY KEY,
            `st_name` VARCHAR(255) NOT NULL,
            `st_type` VARCHAR(50) NOT NULL DEFAULT 'workflow',
            `st_target_id` VARCHAR(100) NOT NULL,
            `st_cron_expression` VARCHAR(100) NULL,
            `st_next_run_at` DATETIME NULL,
            `st_last_run_at` DATETIME NULL,
            `st_status` VARCHAR(20) DEFAULT 'active',
            `st_config` LONGTEXT NULL,
            `st_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
} catch (Throwable $e) {
    echo "Error checking/creating nu_scheduled_triggers table: " . $e->getMessage() . "\n";
}

// Process pending scheduled triggers where st_next_run_at <= NOW() or st_next_run_at IS NULL
try {
    $now = date('Y-m-d H:i:s');
    $triggers = $db->fetchAll(
        "SELECT * FROM nu_scheduled_triggers WHERE st_status = 'active' AND (st_next_run_at IS NULL OR st_next_run_at <= :now)",
        [':now' => $now]
    );

    echo "Found " . count($triggers) . " pending scheduled trigger(s) to process.\n";

    $wfEngine = new WorkflowEngine();

    foreach ($triggers as $trig) {
        echo "Processing Trigger ID #{$trig['st_id']} ({$trig['st_name']})...\n";
        $type = $trig['st_type'];
        $config = !empty($trig['st_config']) ? json_decode($trig['st_config'], true) : [];

        try {
            if ($type === 'workflow') {
                $wfId = (int)$trig['st_target_id'];
                $userId = $config['user_id'] ?? 1;
                $table = $config['table'] ?? null;
                $recordId = $config['record_id'] ?? null;
                $meta = $config['meta'] ?? ['triggered_by' => 'scheduled_cron'];

                $instanceId = $wfEngine->start($wfId, (int)$userId, $table, $recordId, $meta);
                echo " -> Workflow #{$wfId} started successfully. Instance ID: {$instanceId}\n";
            } elseif ($type === 'agent' && class_exists('AgentRuntime')) {
                $agentId = $trig['st_target_id'];
                $prompt = $config['prompt'] ?? 'Scheduled automated task execution.';
                $runtime = new AgentRuntime($db, null);
                $res = $runtime->run($agentId, $prompt, ['triggered_by' => 'scheduled_cron', 'trigger_id' => $trig['st_id']]);
                echo " -> Agent #{$agentId} executed successfully. Run ID: " . ($res['run_id'] ?? 'N/A') . "\n";
            } elseif ($type === 'procedure' && function_exists('nu_run_procedure')) {
                $procCode = $trig['st_target_id'];
                $params = $config['params'] ?? [];
                nu_run_procedure($procCode, $params);
                echo " -> Procedure '{$procCode}' executed successfully.\n";
            }

            // Calculate next run interval (default +1 hour if not specified)
            $intervalSeconds = $config['interval_seconds'] ?? 3600;
            $nextRun = date('Y-m-d H:i:s', time() + (int)$intervalSeconds);

            $db->update(
                'nu_scheduled_triggers',
                [
                    'st_last_run_at' => $now,
                    'st_next_run_at' => $nextRun,
                ],
                'st_id = :id',
                [':id' => $trig['st_id']]
            );

        } catch (Throwable $ex) {
            echo " -> Error processing trigger #{$trig['st_id']}: " . $ex->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "Cron processing error: " . $e->getMessage() . "\n";
}

// Workflow automation: automatic transitions that became true, and SLA overruns / escalations.
try {
    $wfLines = (new WorkflowEngine())->processTimers();
    echo "Workflow timers: " . (count($wfLines) ? "\n  " . implode("\n  ", $wfLines) : "nothing to do") . "\n";
} catch (Throwable $e) {
    echo "Workflow timers error: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Scheduled triggers cron run finished.\n";
