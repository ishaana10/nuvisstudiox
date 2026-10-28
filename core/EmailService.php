<?php
/**
 * EmailService - Core email sending service for nub5-dev
 * Supports PHP mail(), SMTP (via PHPMailer-compatible native SMTP), and sendmail.
 * Can be reused from forms, workflows, and other modules.
 */
class EmailService {

    private $config;
    private $ctx = [];

    public function __construct(array $config = []) {
        // Merge with global config if available
        $defaults = [
            'driver'        => 'mail',   // 'mail' | 'smtp' | 'sendmail'
            'smtp_host'     => '',
            'smtp_port'     => 587,
            'smtp_secure'   => 'tls',    // 'tls' | 'ssl' | ''
            'smtp_auth'     => true,
            'smtp_username' => '',
            'smtp_password' => '',
            'from_email'    => 'noreply@example.com',
            'from_name'     => 'nub5-dev',
            'reply_to'      => '',
            'smtp_verify_peer' => '1',   // set 0 only if the mail host's certificate name does not match
            'smtp_ehlo'     => '',       // optional EHLO name; defaults to the From domain
        ];

        // First attempt to load dynamic SMTP settings from the database
        try {
            $db = NuDatabase::getInstance();
            $rows = $db->fetchAll("SELECT setting_key, setting_value FROM nu_email_settings");
            foreach ($rows as $row) {
                $k = $row['setting_key'];
                $v = $row['setting_value'];
                if ($k === 'smtp_auth') {
                    $defaults[$k] = ($v == '1');
                } else if ($k === 'smtp_verify_peer') {
                    $defaults[$k] = ($v === '' ? '1' : $v);
                } else if ($v !== '') {
                    $defaults[$k] = $v;
                }
            }
        } catch (\Throwable $t) {
            // DB table might not be seeded or created yet
        }

        // Pull from global config.php constants if defined (take priority over DB)
        if (defined('EMAIL_DRIVER'))        $defaults['driver']        = EMAIL_DRIVER;
        if (defined('EMAIL_SMTP_HOST'))     $defaults['smtp_host']     = EMAIL_SMTP_HOST;
        if (defined('EMAIL_SMTP_PORT'))     $defaults['smtp_port']     = EMAIL_SMTP_PORT;
        if (defined('EMAIL_SMTP_SECURE'))   $defaults['smtp_secure']   = EMAIL_SMTP_SECURE;
        if (defined('EMAIL_SMTP_USERNAME')) $defaults['smtp_username'] = EMAIL_SMTP_USERNAME;
        if (defined('EMAIL_SMTP_PASSWORD')) $defaults['smtp_password'] = EMAIL_SMTP_PASSWORD;
        if (defined('EMAIL_FROM'))          $defaults['from_email']    = EMAIL_FROM;
        if (defined('EMAIL_FROM_NAME'))     $defaults['from_name']     = EMAIL_FROM_NAME;

        $this->config = array_merge($defaults, $config);
    }

    /**
     * Send an email.
     *
     * @param string|array $to      Recipient email or ['email' => 'name'] map
     * @param string       $subject Subject line
     * @param string       $body    HTML body
     * @param array        $options ['cc', 'bcc', 'attachments', 'reply_to', 'text_body']
     * @return array ['success' => bool, 'message' => string]
     */
    public function send($to, string $subject, string $body, array $options = []): array {
        $this->ctx = ['source' => (string)($options['source'] ?? ''), 'started' => microtime(true), 'detail' => '', 'message_id' => ''];
        self::ensureLogSchema();
        try {
            if (!$this->normalizeRecipients($to)) throw new \InvalidArgumentException('No valid recipient address.');
            if (!filter_var($this->config['from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('The From address "' . $this->config['from_email'] . '" is not a valid email address. Set it under Email Settings.');
            }
            switch ($this->config['driver']) {
                case 'smtp':
                    return $this->sendSmtp($to, $subject, $body, $options);
                case 'sendmail':
                    return $this->sendMail($to, $subject, $body, $options, true);
                default:
                    return $this->sendMail($to, $subject, $body, $options, false);
            }
        } catch (\Throwable $e) {
            $this->logEmail('FAIL', $to, $subject, $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'detail' => $this->ctx['detail'] ?? ''];
        }
    }

    /** Settings in effect (no secrets) — used by diagnostics. */
    public function getPublicConfig(): array {
        $c = $this->config;
        unset($c['smtp_password']);
        return $c;
    }

    private function messageId(): string {
        $domain = substr(strrchr((string)$this->config['from_email'], '@') ?: '@localhost', 1);
        return '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    }

    // -------------------------------------------------------------------------
    // PHP mail() driver
    // -------------------------------------------------------------------------
    private function sendMail($to, string $subject, string $body, array $options, bool $useSendmail): array {
        $toStr    = $this->formatRecipients($to);
        $mid      = $this->messageId();
        $this->ctx['message_id'] = $mid;
        $headers  = $this->buildHeaders($options);
        $headers .= "Message-ID: {$mid}\r\nDate: " . date('r') . "\r\n";
        $textBody = $options['text_body'] ?? strip_tags($body);

        $mimeParts = $this->buildMultipartBody($body, $textBody, $options);
        $headers .= "Content-Type: " . $mimeParts['contentType'] . "\r\n";
        $fullBody = $mimeParts['body'];

        // -f sets the envelope sender (Return-Path). Without it many hosts (A2 included) send as the
        // account's default address, which breaks SPF alignment and gets mail rejected by Gmail/Yahoo.
        $extraParams = '-f' . escapeshellarg((string)$this->config['from_email']);
        $subjectEnc  = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n") : $subject;
        $result = @mail($toStr, $subjectEnc, $fullBody, $headers, $extraParams);

        $err = $result ? '' : (error_get_last()['message'] ?? 'mail() returned false');
        $this->ctx['detail'] = 'driver=' . ($useSendmail ? 'sendmail' : 'mail()') . '; envelope-from=' . $this->config['from_email']
            . ($result ? '; accepted by the local mail system (this does not guarantee delivery to the recipient)' : '');
        $this->logEmail($result ? 'SENT' : 'FAIL', $to, $subject, $err);
        return ['success' => (bool)$result, 'message' => $result ? 'Handed to the mail system.' : ('mail() failed: ' . $err), 'detail' => $this->ctx['detail'], 'message_id' => $mid];
    }

    // -------------------------------------------------------------------------
    // Native SMTP driver (socket-based, no PHPMailer required)
    // -------------------------------------------------------------------------
    private function sendSmtp($to, string $subject, string $body, array $options): array {
        $host    = trim((string)$this->config['smtp_host']);
        $port    = (int)$this->config['smtp_port'];
        $secure  = strtolower((string)$this->config['smtp_secure']);
        $timeout = 20;
        if ($host === '') throw new \RuntimeException('SMTP is selected but no SMTP host is configured.');
        $this->transcript = [];

        $verify = ((string)($this->config['smtp_verify_peer'] ?? '1')) !== '0';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $address = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $sock = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            $hint = ($secure === 'ssl' && $verify) ? ' If the certificate name does not match the host, turn off "Verify certificate" or use the host name shown on the certificate.' : '';
            throw new \RuntimeException("SMTP connect to {$host}:{$port} failed ({$errno}): {$errstr}." . $hint);
        }
        stream_set_timeout($sock, $timeout);
        $this->ctx['detail'] = '';

        try {
            $recv = $this->smtpRead($sock);
            if (substr($recv, 0, 3) !== '220') throw new \RuntimeException("SMTP greeting failed: {$recv}");

            $fromEmail = (string)$this->config['from_email'];
            $ehlo = trim((string)($this->config['smtp_ehlo'] ?? '')) ?: (substr(strrchr($fromEmail, '@') ?: '', 1) ?: (gethostname() ?: 'localhost'));
            $this->smtpSend($sock, "EHLO {$ehlo}");

            if ($secure === 'tls') {
                $this->smtpSend($sock, 'STARTTLS', '220');
                if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed (certificate problem or the server does not support TLS on this port).');
                }
                $this->smtpSend($sock, "EHLO {$ehlo}");
            }

            if ($this->config['smtp_auth']) {
                $this->smtpSend($sock, 'AUTH LOGIN', '334');
                $this->smtpSend($sock, base64_encode((string)$this->config['smtp_username']), '334', true);
                $this->smtpSend($sock, base64_encode((string)$this->config['smtp_password']), '235', true);
            }

            $this->smtpSend($sock, "MAIL FROM:<{$fromEmail}>");
            $recipients = $this->normalizeRecipients($to);
            foreach ($recipients as $email => $name) $this->smtpSend($sock, "RCPT TO:<{$email}>");
            foreach ((array)($options['cc'] ?? []) as $cc)   if ($cc)  $this->smtpSend($sock, "RCPT TO:<{$cc}>");
            foreach ((array)($options['bcc'] ?? []) as $bcc) if ($bcc) $this->smtpSend($sock, "RCPT TO:<{$bcc}>");
            $this->smtpSend($sock, 'DATA', '354');

            $textBody = $options['text_body'] ?? strip_tags($body);
            $toStr    = $this->formatRecipients($to);
            $fromName = preg_replace('/[\r\n"<>]/', '', (string)$this->config['from_name']);
            $fromStr  = $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail;
            $replyTo  = $options['reply_to'] ?? $this->config['reply_to'];
            $mid      = $this->messageId();
            $this->ctx['message_id'] = $mid;
            $mimeParts = $this->buildMultipartBody($body, $textBody, $options);

            $msg  = "From: {$fromStr}\r\n";
            $msg .= "To: {$toStr}\r\n";
            if (!empty($options['cc']))  $msg .= "Cc: " . implode(', ', (array)$options['cc']) . "\r\n";
            if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $msg .= "Reply-To: {$replyTo}\r\n";
            $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $msg .= "Message-ID: {$mid}\r\n";
            $msg .= "MIME-Version: 1.0\r\n";
            $msg .= "Content-Type: " . $mimeParts['contentType'] . "\r\n";
            $msg .= "Date: " . date('r') . "\r\n\r\n";
            $msg .= $mimeParts['body'];
            // dot-stuffing (RFC 5321 §4.5.2)
            $msg = preg_replace('/^\./m', '..', $msg);

            $resp = $this->smtpSend($sock, $msg . "\r\n.");
            $this->smtpSend($sock, 'QUIT', '221');
            fclose($sock);
            $this->ctx['detail'] = trim($resp) . ' | ' . implode(' | ', array_slice($this->transcript, -6));
            $this->logEmail('SENT', $to, $subject, '');
            return ['success' => true, 'message' => 'Accepted by the SMTP server: ' . trim($resp), 'detail' => $this->ctx['detail'], 'message_id' => $mid];
        } catch (\Throwable $e) {
            @fclose($sock);
            $this->ctx['detail'] = implode("\n", array_slice($this->transcript, -12));
            throw $e;
        }
    }

    private $transcript = [];

    private function smtpRead($sock): string {
        $response = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        if ($response === '') {
            $meta = stream_get_meta_data($sock);
            throw new \RuntimeException(!empty($meta['timed_out']) ? 'SMTP server timed out.' : 'SMTP server closed the connection.');
        }
        $this->transcript[] = 'S: ' . trim(preg_replace('/\s+/', ' ', $response));
        return $response;
    }

    private function smtpSend($sock, string $cmd, string $expectCode = null, bool $secret = false): string {
        fwrite($sock, $cmd . "\r\n");
        $isData = strpos($cmd, "\r\n") !== false;
        $this->transcript[] = 'C: ' . ($secret ? '[hidden]' : ($isData ? '[message body]' : $cmd));
        $response = $this->smtpRead($sock);
        $code = substr($response, 0, 3);
        $expected = $expectCode ?? ($cmd === 'DATA' ? '354' : '2');
        $bad = strlen($expected) === 1 ? ($code[0] !== $expected) : ($code !== $expected);
        if ($bad) {
            $first = strtoupper(explode(' ', trim($cmd))[0] ?? '');
            $shown = $secret ? 'AUTH' : (in_array($first, ['EHLO', 'HELO', 'MAIL', 'RCPT', 'DATA', 'QUIT', 'STARTTLS', 'AUTH'], true) ? $cmd : 'message');
            throw new \RuntimeException("SMTP error for [{$shown}]: " . trim($response));
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------
    private static $logReady = false;

    /** Create / upgrade nu_email_log (adds source, driver, detail, message id, timing, SKIPPED status). */
    public static function ensureLogSchema(): void {
        if (self::$logReady) return;
        self::$logReady = true;
        try {
            $db = NuDatabase::getInstance();
            $pdo = $db->getPdo();
            $sqlite = $db->getDriverName() === 'sqlite';
            if ($sqlite) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS nu_email_log (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient TEXT NOT NULL, subject TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'SENT', error_message TEXT, template_slug TEXT, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
                $have = array_column($pdo->query('PRAGMA table_info(nu_email_log)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `nu_email_log` (
                    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `recipient` VARCHAR(500) NOT NULL, `subject` VARCHAR(255) NOT NULL,
                    `status` VARCHAR(12) NOT NULL DEFAULT 'SENT', `error_message` VARCHAR(1000) DEFAULT NULL, `template_slug` VARCHAR(100) DEFAULT NULL,
                    `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX `idx_status` (`status`), INDEX `idx_sent_at` (`sent_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $have = array_column($pdo->query('SHOW COLUMNS FROM nu_email_log')->fetchAll(PDO::FETCH_ASSOC), 'Field');
                // old installs used ENUM('SENT','FAIL'); allow SKIPPED too
                $st = $pdo->query("SHOW COLUMNS FROM nu_email_log LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
                if ($st && stripos((string)$st['Type'], 'enum') === 0) $pdo->exec("ALTER TABLE nu_email_log MODIFY `status` VARCHAR(12) NOT NULL DEFAULT 'SENT'");
            }
            $add = ['source' => $sqlite ? 'TEXT' : 'VARCHAR(100) NULL', 'driver' => $sqlite ? 'TEXT' : 'VARCHAR(20) NULL',
                    'detail' => $sqlite ? 'TEXT' : 'TEXT NULL', 'message_id' => $sqlite ? 'TEXT' : 'VARCHAR(255) NULL',
                    'duration_ms' => $sqlite ? 'INTEGER' : 'INT NULL', 'project_id' => $sqlite ? 'INTEGER' : 'INT NULL'];
            foreach ($add as $col => $ddl) {
                if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE nu_email_log ADD COLUMN `{$col}` {$ddl}");
            }
        } catch (\Throwable $t) {
            error_log('[EmailService log schema] ' . $t->getMessage());
        }
    }

    /** Record a message that was deliberately not sent (e.g. no customer address). */
    public static function logSkipped($to, string $subject, string $reason, string $source = ''): void {
        self::ensureLogSchema();
        try {
            NuDatabase::getInstance()->insert('nu_email_log', [
                'recipient' => $to === '' ? '(none)' : (is_array($to) ? implode(',', array_keys($to)) : (string)$to),
                'subject' => mb_substr($subject, 0, 255), 'status' => 'SKIPPED', 'error_message' => mb_substr($reason, 0, 1000),
                'source' => mb_substr($source, 0, 100), 'sent_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {}
    }

    private function logEmail(string $status, $to, string $subject, string $error = ''): void {
        try {
            $db = NuDatabase::getInstance();
            $toStr = is_array($to) ? implode(',', array_keys($to)) : (string)$to;
            $dur = isset($this->ctx['started']) ? (int)round((microtime(true) - $this->ctx['started']) * 1000) : null;
            $pid = null;
            try { if (class_exists('ProjectContext')) $pid = ProjectContext::getId(); } catch (\Throwable $t) {}
            $db->insert('nu_email_log', [
                'recipient'     => mb_substr($toStr, 0, 500),
                'subject'       => mb_substr($subject, 0, 255),
                'status'        => $status,
                'error_message' => mb_substr($error, 0, 1000),
                'sent_at'       => date('Y-m-d H:i:s'),
                'source'        => mb_substr((string)($this->ctx['source'] ?? ''), 0, 100),
                'driver'        => (string)$this->config['driver'],
                'detail'        => mb_substr((string)($this->ctx['detail'] ?? ''), 0, 4000),
                'message_id'    => (string)($this->ctx['message_id'] ?? ''),
                'duration_ms'   => $dur,
                'project_id'    => $pid,
            ]);
        } catch (\Throwable $t) {
            error_log('[EmailService log] ' . $t->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Template rendering
    // -------------------------------------------------------------------------
    /**
     * Render an email template from the DB by slug, replacing {{placeholders}}.
     *
     * @param string $slug       Template slug (e.g. 'form_submission')
     * @param array  $variables  Key-value pairs to replace in subject and body
     * @return array ['subject' => string, 'body' => string] or null if not found
     */
    public static function renderTemplate(string $slug, array $variables = [], $db = null): ?array {
        try {
            $db = NuDatabase::getInstance();
            $tpl = $db->fetchOne("SELECT subject, body FROM nu_email_templates WHERE slug = ? AND is_active = 1 LIMIT 1", [$slug]);
            if (!$tpl) return null;

            foreach ($variables as $key => $value) {
                $tpl['subject'] = str_replace('{{' . $key . '}}', (string)$value, $tpl['subject']);
                $tpl['body']    = str_replace('{{' . $key . '}}', (string)$value, $tpl['body']);
            }
            return $tpl;
        } catch (\Throwable $t) {
            return null;
        }
    }

    private function normalizeRecipients($to): array {
        $recipients = [];
        if (is_string($to)) {
            $parts = explode(',', $to);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) continue;
                if (preg_match('/^(.*?)\s*<(.*?)>$/', $part, $matches)) {
                    $email = trim($matches[2]);
                    $name  = trim($matches[1], ' "\'');
                    $recipients[$email] = $name;
                } else {
                    $recipients[$part] = '';
                }
            }
        } elseif (is_array($to)) {
            foreach ($to as $key => $val) {
                if (is_int($key)) {
                    $val = trim($val);
                    if (preg_match('/^(.*?)\s*<(.*?)>$/', $val, $matches)) {
                        $recipients[trim($matches[2])] = trim($matches[1], ' "\'');
                    } else {
                        $recipients[$val] = '';
                    }
                } else {
                    $recipients[trim($key)] = trim($val);
                }
            }
        }
        return $recipients;
    }

    private function formatRecipients($to): string {
        $normalized = $this->normalizeRecipients($to);
        $formatted = [];
        foreach ($normalized as $email => $name) {
            if ($name !== '') {
                $formatted[] = "\"$name\" <$email>";
            } else {
                $formatted[] = $email;
            }
        }
        return implode(', ', $formatted);
    }

    /**
     * Builds the MIME multipart body and returns an array:
     * ['contentType' => string, 'body' => string]
     */
    private function buildMultipartBody(string $body, string $textBody, array $options): array {
        $attachments = $options['attachments'] ?? [];
        if (empty($attachments)) {
            $boundary = md5(uniqid((string)time()));
            $contentType = "multipart/alternative; boundary=\"$boundary\"";
            $fullBody  = "--$boundary\r\n";
            $fullBody .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $fullBody .= chunk_split(base64_encode($textBody)) . "\r\n";
            $fullBody .= "--$boundary\r\n";
            $fullBody .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $fullBody .= chunk_split(base64_encode($body)) . "\r\n";
            $fullBody .= "--{$boundary}--";
            return ['contentType' => $contentType, 'body' => $fullBody];
        }

        $mixedBoundary = 'mixed_' . md5(uniqid((string)time()));
        $altBoundary   = 'alt_' . md5(uniqid((string)time()));

        $contentType = "multipart/mixed; boundary=\"$mixedBoundary\"";

        $fullBody  = "--{$mixedBoundary}\r\n";
        $fullBody .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";

        $fullBody .= "--{$altBoundary}\r\n";
        $fullBody .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $fullBody .= chunk_split(base64_encode($textBody)) . "\r\n";

        $fullBody .= "--{$altBoundary}\r\n";
        $fullBody .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $fullBody .= chunk_split(base64_encode($body)) . "\r\n";

        $fullBody .= "--{$altBoundary}--\r\n";

        foreach ($attachments as $attach) {
            $filename = $attach['filename'] ?? 'attachment.dat';
            $mimetype = $attach['mimetype'] ?? 'application/octet-stream';
            $data     = $attach['data'] ?? '';

            $fullBody .= "--{$mixedBoundary}\r\n";
            $fullBody .= "Content-Type: {$mimetype}; name=\"{$filename}\"\r\n";
            $fullBody .= "Content-Transfer-Encoding: base64\r\n";
            $fullBody .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $fullBody .= chunk_split(base64_encode($data)) . "\r\n";
        }

        $fullBody .= "--{$mixedBoundary}--";

        return ['contentType' => $contentType, 'body' => $fullBody];
    }

    private function buildHeaders(array $options): string {
        $fromEmail = $this->config['from_email'];
        $fromName  = $this->config['from_name'];
        $fromStr   = $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail;
        $replyTo   = $options['reply_to'] ?? $this->config['reply_to'];

        $headers  = "From: {$fromStr}\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: {$replyTo}\r\n";
        }
        if (!empty($options['cc'])) {
            $cc = is_array($options['cc']) ? implode(', ', $options['cc']) : $options['cc'];
            $headers .= "Cc: {$cc}\r\n";
        }
        if (!empty($options['bcc'])) {
            $bcc = is_array($options['bcc']) ? implode(', ', $options['bcc']) : $options['bcc'];
            $headers .= "Bcc: {$bcc}\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "X-Mailer: nub5-dev\r\n";
        return $headers;
    }
}
