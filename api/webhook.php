<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Access restricted to globeadmin or admin
$currUser = $auth->getCurrentUser();
$role     = strtolower($currUser['usr_role'] ?? '');
if ($role !== 'globeadmin' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: Admin only']);
    exit;
}

$db = NuDatabase::getInstance();

// ── Run Safe DDL Auto-Migrations ───────────────────────────────────────────
try {
    // 1. Migrate nu_webhooks columns
    $whCols = $db->fetchAll("SHOW COLUMNS FROM `nu_webhooks`");
    $whNames = array_column($whCols, 'Field');
    if (!in_array('webhook_method', $whNames, true)) {
        $db->exec("ALTER TABLE `nu_webhooks` ADD COLUMN `webhook_method` VARCHAR(10) NOT NULL DEFAULT 'POST' AFTER `webhook_url`");
    }
    if (!in_array('webhook_headers', $whNames, true)) {
        $db->exec("ALTER TABLE `nu_webhooks` ADD COLUMN `webhook_headers` TEXT DEFAULT NULL AFTER `webhook_method`");
    }
    if (!in_array('webhook_payload_template', $whNames, true)) {
        $db->exec("ALTER TABLE `nu_webhooks` ADD COLUMN `webhook_payload_template` TEXT DEFAULT NULL AFTER `webhook_headers`");
    }

    // 2. Migrate nu_api_tokens columns
    $tokCols = $db->fetchAll("SHOW COLUMNS FROM `nu_api_tokens`");
    $tokNames = array_column($tokCols, 'Field');
    if (!in_array('token_expires_at', $tokNames, true)) {
        if (in_array('token_expires', $tokNames, true)) {
            $db->exec("ALTER TABLE `nu_api_tokens` CHANGE COLUMN `token_expires` `token_expires_at` DATETIME DEFAULT NULL");
        } else {
            $db->exec("ALTER TABLE `nu_api_tokens` ADD COLUMN `token_expires_at` DATETIME DEFAULT NULL AFTER `token_name`");
        }
    }
} catch (Throwable $e) {
    error_log('[Webhook Schema Auto-Migration Error] ' . $e->getMessage());
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        try {
            $webhooks = $db->fetchAll("SELECT * FROM nu_webhooks ORDER BY webhook_created_at DESC");
            echo json_encode(['success' => true, 'webhooks' => $webhooks]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'save':
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = $input['webhook_id'] ?? null;

            $data = [
                'webhook_name'             => trim((string)($input['webhook_name'] ?? '')),
                'webhook_url'              => trim((string)($input['webhook_url'] ?? '')),
                'webhook_method'           => strtoupper(trim((string)($input['webhook_method'] ?? 'POST'))),
                'webhook_headers'          => $input['webhook_headers'] ?? null,
                'webhook_payload_template' => $input['webhook_payload_template'] ?? null,
                'webhook_events'           => trim((string)($input['webhook_events'] ?? '')),
                'webhook_secret'           => trim((string)($input['webhook_secret'] ?? '')),
                'webhook_active'           => (int)($input['webhook_active'] ?? 1)
            ];

            if ($data['webhook_name'] === '' || $data['webhook_url'] === '') {
                echo json_encode(['success' => false, 'error' => 'Name and URL are required fields']);
                exit;
            }

            if ($id) {
                $db->update('nu_webhooks', $data, 'webhook_id = :id', [':id' => (int)$id]);
                echo json_encode(['success' => true, 'message' => 'Webhook updated successfully']);
            } else {
                $newId = $db->insert('nu_webhooks', $data);
                echo json_encode(['success' => true, 'message' => 'Webhook created successfully', 'webhook_id' => $newId]);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'delete':
        try {
            $id = $_GET['id'] ?? 0;
            $db->delete('nu_webhooks', 'webhook_id = :id', [':id' => (int)$id]);
            echo json_encode(['success' => true, 'message' => 'Webhook deleted successfully']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete webhook: ' . $e->getMessage()]);
        }
        break;

    case 'test':
        try {
            $id = $_GET['id'] ?? 0;
            $webhook = $db->fetchOne("SELECT * FROM nu_webhooks WHERE webhook_id = :id", [':id' => (int)$id]);
            if (!$webhook) {
                echo json_encode(['success' => false, 'error' => 'Webhook not found']);
                exit;
            }

            require_once __DIR__ . '/../core/WebhookSender.php';

            $testPayload = [
                'event_type' => 'test_webhook',
                'record_id'  => '999',
                'table'      => 'nu_test_table',
                'data'       => [
                    'test_message' => 'Hello! This is a customizable test webhook payload from Nuvis StudioX.',
                    'system_time'  => date('Y-m-d H:i:s'),
                    'version'      => NU_VERSION
                ]
            ];

            $result = NuWebhookSender::send($webhook, 'test_webhook', $testPayload);
            echo json_encode(array_merge(['success' => $result['success']], $result));
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'list_users':
        try {
            $users = $db->fetchAll("SELECT usr_id, usr_username, usr_name, usr_role FROM nu_users WHERE usr_active = 1 ORDER BY usr_username ASC");
            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'generate_token':
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $name   = trim((string)($input['token_name'] ?? ''));
            $userId = trim((string)($input['token_user_id'] ?? ''));
            $expiry = trim((string)($input['token_expires_at'] ?? ''));

            if ($name === '' || $userId === '') {
                echo json_encode(['success' => false, 'error' => 'Name and mapped System User are required']);
                exit;
            }

            // Generate cryptographically secure API Key
            $rawToken = 'nv_tok_' . bin2hex(random_bytes(24));

            $data = [
                'token_key'        => $rawToken,
                'token_name'       => $name,
                'token_user_id'    => $userId,
                'token_active'     => 1,
                'token_expires_at' => ($expiry !== '') ? $expiry : null
            ];

            $db->insert('nu_api_tokens', $data);

            echo json_encode([
                'success'   => true,
                'token_key' => $rawToken,
                'message'   => 'Token generated successfully. Please copy it now, it will not be shown again!'
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'revoke_token':
        try {
            $id = $_GET['id'] ?? 0;
            $db->delete('nu_api_tokens', 'token_id = :id', [':id' => (int)$id]);
            echo json_encode(['success' => true, 'message' => 'Token revoked successfully']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to revoke token: ' . $e->getMessage()]);
        }
        break;

    case 'save_and_test_playground':
        try {
            if (empty($GLOBALS['nuConfig']['enableWebhookDemo'])) {
                echo json_encode(['success' => false, 'error' => 'Webhook Demo playground is disabled']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $url = trim((string)($input['url'] ?? ''));
            $secret = trim((string)($input['secret'] ?? ''));
            $eventType = trim((string)($input['event_type'] ?? ''));
            $payloadData = $input['payload_data'] ?? [];
            $template = $input['template'] ?? '';

            if ($url === '') {
                echo json_encode(['success' => false, 'error' => 'Target URL is required']);
                exit;
            }

            require_once __DIR__ . '/../core/WebhookSender.php';

            $webhook = [
                'webhook_id'               => 9999,
                'webhook_name'             => 'Playground Temp Route',
                'webhook_url'              => $url,
                'webhook_method'           => 'POST',
                'webhook_headers'          => json_encode(['Content-Type' => 'application/json']),
                'webhook_payload_template' => $template,
                'webhook_secret'           => $secret,
                'webhook_active'           => 1
            ];

            // Trigger the real core sender
            $result = NuWebhookSender::send($webhook, $eventType, $payloadData);

            $computedSignature = '';
            if ($secret !== '') {
                $computedSignature = hash_hmac('sha256', $result['payload'], $secret);
            }

            // Also log to the database log table as persistent backup
            try {
                $db->insert('nu_webhook_logs', [
                    'delivery_id' => uniqid('dlv_', true),
                    'webhook_id'  => 9999,
                    'event_type'  => $eventType,
                    'method'      => 'POST',
                    'url'         => $url,
                    'headers'     => json_encode(['X-Nuvis-Event' => $eventType, 'X-Nuvis-Signature' => $computedSignature]),
                    'payload'     => $result['payload'],
                    'response'    => $result['response'] ?: '',
                    'http_code'   => $result['http_code'] ?? 0,
                    'status'      => $result['success'] ? 'success' : 'failed'
                ]);
            } catch (Throwable $ignore) {}

            echo json_encode([
                'success'              => true,
                'signature'            => $computedSignature,
                'computed_signature'   => $computedSignature,
                'raw_rendered_payload' => $result['payload'],
                'dispatch_result'      => $result
            ]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown API action']);
}
?>
