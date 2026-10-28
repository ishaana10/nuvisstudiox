<?php
/**
 * api/email.php
 * REST API endpoint for email operations:
 *   POST  action=send            - Send email (direct or via template)
 *   POST  action=test            - Send test email to verify SMTP config
 *   GET   action=templates       - List all email templates
 *   POST  action=save_template   - Create or update a template
 *   POST  action=delete_template - Delete a template
 *   GET   action=logs            - Paginated email send log
 *   GET   action=get_settings    - Retrieve DB email settings
 *   POST  action=save_settings   - Persist email settings to DB
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/EmailService.php';
require_once __DIR__ . '/../core/Auth.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised']);
    exit;
}

$db     = NuDatabase::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

$input = [];
if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    if (empty($input)) $input = $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? '';

// Settings, logs, diagnostics and test sends are administrator-only.
$adminOnly = ['test', 'logs', 'get_settings', 'save_settings', 'deliverability', 'clear_logs', 'logs_csv'];
if (in_array($action, $adminOnly, true)) {
    $u = $auth->getCurrentUser();
    $role = strtolower((string)(is_array($u) ? ($u['usr_role'] ?? '') : ''));
    if (!in_array($role, ['admin', 'globeadmin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator access required.']);
        exit;
    }
}
EmailService::ensureLogSchema();

// Ensure email tables exist
try {
    $db->fetchAll("SELECT 1 FROM nu_email_settings LIMIT 1");
} catch (Throwable $t) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `nu_email_settings` (
              `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
              `setting_value` TEXT NOT NULL,
              `updated_at`    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $db->exec("
            INSERT IGNORE INTO `nu_email_settings` (`setting_key`, `setting_value`) VALUES
              ('driver',        'mail'),
              ('smtp_host',     ''),
              ('smtp_port',     '587'),
              ('smtp_secure',   'tls'),
              ('smtp_auth',     '1'),
              ('smtp_username', ''),
              ('smtp_password', ''),
              ('from_email',    'noreply@example.com'),
              ('from_name',     'nub5-dev');
        ");
    } catch (Throwable $t2) {}
}

// Ensure high-quality email templates are seeded
try {
    $db->exec("
        INSERT IGNORE INTO `nu_email_templates` (`name`, `slug`, `subject`, `body`, `description`) VALUES
        (
          'Form Submission Notification',
          'form_submission',
          'New form submission: {{form_name}}',
          '<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">\n<h2 style=\"color:#2d7dd2\">New Form Submission</h2>\n<p>A new submission was received for the form <strong>{{form_name}}</strong>.</p>\n<table style=\"width:100%;border-collapse:collapse\">\n  <tr><td style=\"padding:8px;border:1px solid #ddd\"><strong>Submitted by</strong></td><td style=\"padding:8px;border:1px solid #ddd\">{{submitted_by}}</td></tr>\n  <tr><td style=\"padding:8px;border:1px solid #ddd\"><strong>Date</strong></td><td style=\"padding:8px;border:1px solid #ddd\">{{submitted_at}}</td></tr>\n  <tr><td style=\"padding:8px;border:1px solid #ddd\"><strong>Record ID</strong></td><td style=\"padding:8px;border:1px solid #ddd\">{{record_id}}</td></tr>\n</table>\n<p style=\"margin-top:16px\"><a href=\"{{review_url}}\" style=\"background:#2d7dd2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px\">Review Submission</a></p>\n<hr/><p style=\"font-size:12px;color:#888\">This is an automated notification from nub5-dev.</p>\n</body></html>',
          'Sent when a form is submitted. Variables: {{form_name}}, {{submitted_by}}, {{submitted_at}}, {{record_id}}, {{review_url}}'
        ),
        (
          'Welcome / Account Created',
          'user_welcome',
          'Welcome to {{app_name}}, {{user_name}}!',
          '<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">\n<h2 style=\"color:#2d7dd2\">Welcome, {{user_name}}!</h2>\n<p>Your account on <strong>{{app_name}}</strong> has been created.</p>\n<p><strong>Username:</strong> {{username}}<br/><strong>Temporary Password:</strong> {{temp_password}}</p>\n<p>Please log in and change your password immediately.</p>\n<p><a href=\"{{login_url}}\" style=\"background:#2d7dd2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px\">Log In Now</a></p>\n<hr/><p style=\"font-size:12px;color:#888\">nub5-dev Automated Notification</p>\n</body></html>',
          'Sent when a new user account is created. Variables: {{user_name}}, {{username}}, {{app_name}}, {{temp_password}}, {{login_url}}'
        ),
        (
          'Password Reset',
          'password_reset',
          'Password Reset Request - {{app_name}}',
          '<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">\n<h2 style=\"color:#2d7dd2\">Password Reset</h2>\n<p>Hi {{user_name}}, we received a request to reset your password.</p>\n<p><a href=\"{{reset_url}}\" style=\"background:#e63946;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px\">Reset My Password</a></p>\n<p style=\"color:#888;font-size:12px\">This link expires in 1 hour. If you did not request this, ignore this email.</p>\n<hr/><p style=\"font-size:12px;color:#888\">nub5-dev Automated Notification</p>\n</body></html>',
          'Password reset link email. Variables: {{user_name}}, {{app_name}}, {{reset_url}}'
        ),
        (
          'Workflow Action Notification',
          'workflow_notification',
          'Action Required: {{workflow_name}} - Step {{step_name}}',
          '<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">\n<h2 style=\"color:#2d7dd2\">Workflow Notification</h2>\n<p>Hi {{recipient_name}},</p>\n<p>The workflow <strong>{{workflow_name}}</strong> requires your attention at step: <strong>{{step_name}}</strong>.</p>\n<p><strong>Details:</strong> {{message}}</p>\n<p><a href=\"{{action_url}}\" style=\"background:#2d7dd2;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px\">Take Action</a></p>\n<hr/><p style=\"font-size:12px;color:#888\">nub5-dev Automated Notification</p>\n</body></html>',
          'Workflow step notification. Variables: {{recipient_name}}, {{workflow_name}}, {{step_name}}, {{message}}, {{action_url}}'
        ),
        (
          'Password Changed Notification',
          'password_changed',
          'Your password has been changed successfully - {{app_name}}',
          '<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">\n<h2 style=\"color:#10b981\">Password Changed Successfully</h2>\n<p>Hi {{user_name}},</p>\n<p>Your password for your account <strong>{{username}}</strong> has been updated successfully.</p>\n<p>If you did not perform this change, please contact an administrator or reset your password immediately.</p>\n<p><a href=\"{{login_url}}\" style=\"background:#10b981;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px\">Log In to Your Account</a></p>\n<hr/><p style=\"font-size:12px;color:#888\">nub5-dev Automated Notification</p>\n</body></html>',
          'Sent to notify users when their password is changed. Variables: {{user_name}}, {{username}}, {{app_name}}, {{login_url}}'
        ),
        (
          'Application Cloned / Registered',
          'app_registered',
          'New Application Registered: {{app_name}}',
          '<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">\n<h2 style=\"color:#2d7dd2\">New Application Cloned</h2>\n<p>Hello,</p>\n<p>A new application template has been cloned or registered successfully:</p>\n<table style=\"width:100%;border-collapse:collapse\">\n  <tr><td style=\"padding:8px;border:1px solid #ddd\"><strong>App Name</strong></td><td style=\"padding:8px;border:1px solid #ddd\">{{app_name}}</td></tr>\n  <tr><td style=\"padding:8px;border:1px solid #ddd\"><strong>Registered By</strong></td><td style=\"padding:8px;border:1px solid #ddd\">{{registered_by}}</td></tr>\n  <tr><td style=\"padding:8px;border:1px solid #ddd\"><strong>Timestamp</strong></td><td style=\"padding:8px;border:1px solid #ddd\">{{registered_at}}</td></tr>\n</table>\n<hr/><p style=\"font-size:12px;color:#888\">nub5-dev Automated Notification</p>\n</body></html>',
          'Sent to notify admin when a new application is registered or cloned. Variables: {{app_name}}, {{registered_by}}, {{registered_at}}'
        ),
        (
          'Account Lockout Warning',
          'account_lockout',
          'Security Alert: Account Temporarily Locked - {{app_name}}',
          '<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;max-width:600px;margin:auto\">\n<h2 style=\"color:#ef4444\">Security Alert: Account Locked</h2>\n<p>Hi {{user_name}},</p>\n<p>Your account has been temporarily locked due to too many failed login attempts.</p>\n<p><strong>Lockout Duration:</strong> {{lockout_duration}} minutes</p>\n<p>If this was not you, someone may be trying to access your account.</p>\n<hr/><p style=\"font-size:12px;color:#888\">nub5-dev Automated Notification</p>\n</body></html>',
          'Sent when a user account is locked. Variables: {{user_name}}, {{app_name}}, {{lockout_duration}}'
        );
    ");
} catch (Throwable $t) {}

try {
    $db->fetchAll("SELECT 1 FROM nu_email_templates LIMIT 1");
} catch (Throwable $t) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `nu_email_templates` (
              `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `name`        VARCHAR(150) NOT NULL,
              `slug`        VARCHAR(100) NOT NULL UNIQUE,
              `description` TEXT,
              `subject`     VARCHAR(255) NOT NULL,
              `body`        LONGTEXT NOT NULL,
              `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
              `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
              `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t2) {}
}

try {
    $db->fetchAll("SELECT 1 FROM nu_email_log LIMIT 1");
} catch (Throwable $t) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `nu_email_log` (
              `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `recipient`     VARCHAR(500) NOT NULL,
              `subject`       VARCHAR(255) NOT NULL,
              `status`        ENUM('SENT','FAIL') NOT NULL DEFAULT 'SENT',
              `error_message` VARCHAR(1000) DEFAULT NULL,
              `template_slug` VARCHAR(100) DEFAULT NULL,
              `sent_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_status`   (`status`),
              INDEX `idx_sent_at`  (`sent_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $t2) {}
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        case 'send':
            $to      = $input['to']      ?? '';
            $subject = $input['subject'] ?? '';
            $body    = $input['body']    ?? '';
            $tplSlug = $input['template_slug'] ?? '';
            $vars    = $input['variables'] ?? [];
            $options = [
                'cc'        => $input['cc']  ?? [],
                'bcc'       => $input['bcc'] ?? [],
                'reply_to'  => $input['reply_to'] ?? '',
            ];

            if (!$to) throw new \InvalidArgumentException('Recipient (to) is required.');

            if ($tplSlug) {
                $rendered = EmailService::renderTemplate($tplSlug, $vars);
                if (!$rendered) throw new \RuntimeException("Template '{$tplSlug}' not found or inactive.");
                $subject = $rendered['subject'];
                $body    = $rendered['body'];
            }

            if (!$subject || !$body) throw new \InvalidArgumentException('Subject and body are required.');

            $svc    = new EmailService();
            $result = $svc->send($to, $subject, $body, $options);
            echo json_encode($result);
            break;

        // ------------------------------------------------------------------
        case 'test':
            $currentUser = $auth->getCurrentUser();
            $to  = trim((string)($input['to'] ?? ($currentUser['usr_email'] ?? '')));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('A valid recipient email is required.');
            $svc    = new EmailService();
            try { require_once __DIR__ . '/../core/Audit.php'; (new NuAudit())->event('email_test', 'Test email requested for ' . $to); } catch (\Throwable $t) {}
            $result = $svc->send($to, 'Nuvis StudioX email test', '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px"><h2 style="margin:0 0 8px">Email delivery is working ✓</h2><p style="color:#475569">This test message was sent from your Nuvis StudioX email settings on ' . date('Y-m-d H:i') . '.</p></div>', ['source' => 'test', 'text_body' => 'Email delivery is working. Sent from your Nuvis StudioX email settings.']);
            echo json_encode($result);
            break;
        case 'templates':
            $templates = $db->fetchAll("SELECT * FROM nu_email_templates ORDER BY name ASC");
            echo json_encode(['success' => true, 'data' => $templates]);
            break;

        // ------------------------------------------------------------------
        case 'save_template':
            $id          = (int)($input['id'] ?? 0);
            $name        = trim((string)($input['name'] ?? ''));
            $slug        = trim((string)($input['slug'] ?? ''));
            $subject     = trim((string)($input['subject'] ?? ''));
            $body        = trim((string)($input['body'] ?? ''));
            $description = trim((string)($input['description'] ?? ''));
            $active      = (int)($input['is_active'] ?? 1);

            if (!$name || !$slug || !$subject || !$body)
                throw new \InvalidArgumentException('name, slug, subject, and body are required.');

            if ($id > 0) {
                $db->query(
                    "UPDATE nu_email_templates SET name=?, slug=?, subject=?, body=?, description=?, is_active=?, updated_at=NOW() WHERE id=?",
                    [$name, $slug, $subject, $body, $description, $active, $id]
                );
                echo json_encode(['success' => true, 'message' => 'Template updated.']);
            } else {
                $db->query(
                    "INSERT INTO nu_email_templates (name, slug, subject, body, description, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [$name, $slug, $subject, $body, $description, $active]
                );
                echo json_encode(['success' => true, 'message' => 'Template created.']);
            }
            break;

        // ------------------------------------------------------------------
        case 'delete_template':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('Template id required.');
            $db->query("DELETE FROM nu_email_templates WHERE id=?", [$id]);
            echo json_encode(['success' => true, 'message' => 'Template deleted.']);
            break;

        // ------------------------------------------------------------------
        case 'logs':
        case 'logs_csv':
            $limit  = $action === 'logs_csv' ? 5000 : max(1, min((int)($_GET['limit'] ?? 50), 200));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $where = []; $p = [];
            $st = strtoupper(trim((string)($_GET['status'] ?? '')));
            if (in_array($st, ['SENT', 'FAIL', 'SKIPPED'], true)) { $where[] = 'status = ?'; $p[] = $st; }
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $where[] = '(recipient LIKE ? OR subject LIKE ? OR error_message LIKE ? OR source LIKE ?)';
                array_push($p, $like, $like, $like, $like);
            }
            $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $logs = $db->fetchAll("SELECT * FROM nu_email_log{$w} ORDER BY id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset, $p);
            if ($action === 'logs_csv') {
                header_remove('Content-Type');
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="email-log-' . date('Ymd-His') . '.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['id', 'time', 'status', 'recipient', 'subject', 'source', 'driver', 'error', 'detail', 'message_id', 'ms']);
                foreach ($logs as $r) {
                    $cell = function ($v) { $v = (string)$v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; };
                    fputcsv($out, [$r['id'], $r['sent_at'], $r['status'], $cell($r['recipient']), $cell($r['subject']), $r['source'] ?? '', $r['driver'] ?? '', $cell($r['error_message'] ?? ''), $cell($r['detail'] ?? ''), $r['message_id'] ?? '', $r['duration_ms'] ?? '']);
                }
                fclose($out);
                exit;
            }
            $countRow = $db->fetchOne("SELECT COUNT(*) AS c FROM nu_email_log{$w}", $p);
            $stats = $db->fetchAll("SELECT status, COUNT(*) AS c FROM nu_email_log GROUP BY status");
            echo json_encode(['success' => true, 'data' => $logs, 'total' => (int)($countRow['c'] ?? 0), 'stats' => $stats]);
            break;
        case 'clear_logs':
            $days = max(0, (int)($input['older_than_days'] ?? 0));
            if ($days > 0) $db->query("DELETE FROM nu_email_log WHERE sent_at < ?", [date('Y-m-d H:i:s', time() - $days * 86400)]);
            else $db->query("DELETE FROM nu_email_log");
            echo json_encode(['success' => true, 'message' => 'Log cleared.']);
            break;
        case 'get_settings':
            $rows   = $db->fetchAll("SELECT setting_key, setting_value FROM nu_email_settings");
            $config = [];
            foreach ($rows as $row) {
                $config[$row['setting_key']] = $row['setting_value'];
            }
            // never send the stored password back to the browser
            $hasPw = !empty($config['smtp_password']);
            unset($config['smtp_password']);
            $config['smtp_password_set'] = $hasPw ? '1' : '0';
            echo json_encode(['success' => true, 'data' => $config]);
            break;
        case 'deliverability':
            $svc = new EmailService();
            $c = $svc->getPublicConfig();
            $from = (string)$c['from_email'];
            $domain = strtolower(substr(strrchr($from, '@') ?: '', 1));
            $checks = [];
            $add = function ($id, $level, $title, $msg) use (&$checks) { $checks[] = compact('id', 'level', 'title', 'msg'); };
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $add('from', 'error', 'From address', 'The From address is missing or invalid.');
            } elseif (in_array($domain, ['example.com', 'localhost', 'localhost.localdomain', 'example.org'], true) || strpos($domain, '.') === false) {
                $add('from', 'error', 'From address', "\"{$from}\" is a placeholder. Use a real mailbox on your own domain, e.g. noreply@yourdomain.com.");
            } else {
                $add('from', 'ok', 'From address', $from);
            }
            if (($c['driver'] ?? '') === 'smtp') {
                $add('driver', 'ok', 'Sending method', 'Authenticated SMTP via ' . $c['smtp_host'] . ':' . $c['smtp_port'] . ' (' . strtoupper((string)$c['smtp_secure'] ?: 'none') . ')');
                if (empty($c['smtp_auth'])) $add('auth', 'warn', 'SMTP login', 'SMTP authentication is off. Most providers (and A2 Hosting) require a username and password.');
            } else {
                $add('driver', 'warn', 'Sending method', 'Using the server\'s built-in mail() function. Gmail/Yahoo often reject or spam-folder this. Switch to SMTP with a mailbox created in cPanel.');
            }
            if ($domain && strpos($domain, '.') !== false && function_exists('dns_get_record')) {
                $txt = @dns_get_record($domain, DNS_TXT) ?: [];
                $spf = ''; foreach ($txt as $t) { $v = $t['txt'] ?? ''; if (stripos($v, 'v=spf1') === 0) $spf = $v; }
                $add('spf', $spf ? 'ok' : 'error', 'SPF record', $spf ?: "No SPF record on {$domain}. Add a TXT record, e.g. \"v=spf1 +a +mx +include:spf.a2hosting.com ~all\" (check your host's recommended value).");
                $dm = @dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [];
                $dmv = ''; foreach ($dm as $t) { if (stripos($t['txt'] ?? '', 'v=DMARC1') === 0) $dmv = $t['txt']; }
                $add('dmarc', $dmv ? 'ok' : 'warn', 'DMARC record', $dmv ?: "No DMARC record. Gmail and Yahoo require one for bulk senders. Add TXT _dmarc.{$domain}: \"v=DMARC1; p=none; rua=mailto:{$from}\".");
                $dk = []; foreach (['default', 'selector1', 'google', 'mail', 'k1', 's1'] as $sel) { $r = @dns_get_record("{$sel}._domainkey.{$domain}", DNS_TXT); if ($r) { $dk[] = $sel; } }
                $add('dkim', $dk ? 'ok' : 'warn', 'DKIM record', $dk ? 'Found selector: ' . implode(', ', $dk) : 'No common DKIM selector found. In cPanel > Email Deliverability, enable/repair DKIM for ' . $domain . '.');
                $mx = @dns_get_record($domain, DNS_MX) ?: [];
                $add('mx', $mx ? 'ok' : 'warn', 'MX record', $mx ? implode(', ', array_map(fn($m) => $m['target'], $mx)) : 'No MX record; some receivers distrust domains that cannot receive mail.');
            } else {
                $add('dns', 'warn', 'DNS checks', 'DNS lookups are unavailable on this server.');
            }
            echo json_encode(['success' => true, 'domain' => $domain, 'checks' => $checks]);
            break;

        // ------------------------------------------------------------------
        case 'save_settings':
            $settings = $input['settings'] ?? [];
            if (empty($settings)) throw new \InvalidArgumentException('No settings provided.');
            $known = ['driver','smtp_host','smtp_port','smtp_secure','smtp_auth','smtp_username','smtp_password','from_name','from_email','reply_to','smtp_verify_peer','smtp_ehlo'];
            foreach ($settings as $setting) {
                $key   = trim((string)($setting['key']   ?? ''));
                $value = trim((string)($setting['value'] ?? ''));
                if (!in_array($key, $known, true)) continue;
                if ($key === 'smtp_password' && ($value === '' || $value === '********')) continue; // blank = keep current
                if (in_array($key, ['from_email', 'reply_to'], true) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("\"{$value}\" is not a valid email address.");
                }
                $exists = $db->fetchOne("SELECT 1 AS x FROM nu_email_settings WHERE setting_key = ?", [$key]);
                if ($exists) $db->query("UPDATE nu_email_settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
                else $db->query("INSERT INTO nu_email_settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
            }
            try { require_once __DIR__ . '/../core/Audit.php'; (new NuAudit())->event('email_settings_save', 'Email settings changed', ['keys' => array_values(array_filter(array_map(fn($x) => $x['key'] ?? null, $settings)))]); } catch (\Throwable $t) {}
            echo json_encode(['success' => true, 'message' => 'Settings saved.']);
            break;

        // ------------------------------------------------------------------
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
