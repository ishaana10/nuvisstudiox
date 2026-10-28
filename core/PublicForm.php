<?php
declare(strict_types=1);

/**
 * NuPublicForm — share a form through a public link so customers can submit a
 * request without signing in.
 *
 * A "share" is one row in nu_form_shares per form (token, on/off switch, expiry, cap,
 * which fields to show, messages, notification emails). Submissions are INSERT-only:
 * a public visitor can never read, change or delete a record.
 *
 * Only simple, self-contained field types are exposed (see SUPPORTED). Anything that
 * would reveal other data or needs a login (lookups, SQL/table-backed dropdowns, file
 * uploads, sub-forms, calculated fields, ...) is left out and reported to the admin.
 */
class NuPublicForm
{
    /** Field types a public visitor can fill in. */
    public const SUPPORTED = [
        'text', 'textarea', 'number', 'email', 'tel', 'phone', 'url',
        'date', 'time', 'datetime', 'select', 'radio', 'checkbox', 'checkbox_group',
    ];

    private static bool $schemaChecked = false;

    // ── Schema ────────────────────────────────────────────────────────────────
    public static function ensureSchema(NuDatabase $db): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        $sqlite = $db->getDriverName() === 'sqlite';
        $pdo = $db->getPdo();
        try {
            if ($sqlite) {
                $pdo->exec('CREATE TABLE IF NOT EXISTS `nu_form_shares` (
                    `fs_id` INTEGER PRIMARY KEY AUTOINCREMENT, `project_id` INTEGER NOT NULL DEFAULT 1,
                    `fs_form_code` TEXT NOT NULL, `fs_token` TEXT NOT NULL UNIQUE, `fs_secret` TEXT NOT NULL,
                    `fs_enabled` INTEGER NOT NULL DEFAULT 0, `fs_title` TEXT, `fs_intro` TEXT,
                    `fs_submit_label` TEXT, `fs_success_message` TEXT, `fs_redirect_url` TEXT,
                    `fs_notify_email` TEXT, `fs_confirm_email` INTEGER NOT NULL DEFAULT 0, `fs_fields` TEXT,
                    `fs_expires_at` DATETIME NULL, `fs_max_submissions` INTEGER NULL,
                    `fs_submit_count` INTEGER NOT NULL DEFAULT 0, `fs_created_by` TEXT,
                    `fs_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `fs_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (`project_id`, `fs_form_code`))');
                $pdo->exec('CREATE TABLE IF NOT EXISTS `nu_form_share_log` (
                    `fl_id` INTEGER PRIMARY KEY AUTOINCREMENT, `fl_fs_id` INTEGER NOT NULL, `fl_record_id` TEXT,
                    `fl_ip_hash` TEXT, `fl_ua` TEXT, `fl_at` DATETIME DEFAULT CURRENT_TIMESTAMP)');
            } else {
                $pdo->exec('CREATE TABLE IF NOT EXISTS `nu_form_shares` (
                    `fs_id` INT NOT NULL AUTO_INCREMENT, `project_id` INT UNSIGNED NOT NULL DEFAULT 1,
                    `fs_form_code` VARCHAR(50) NOT NULL, `fs_token` VARCHAR(64) NOT NULL, `fs_secret` VARCHAR(64) NOT NULL,
                    `fs_enabled` TINYINT(1) NOT NULL DEFAULT 0, `fs_title` VARCHAR(200) DEFAULT NULL, `fs_intro` TEXT NULL,
                    `fs_submit_label` VARCHAR(60) DEFAULT NULL, `fs_success_message` TEXT NULL, `fs_redirect_url` VARCHAR(500) DEFAULT NULL,
                    `fs_notify_email` VARCHAR(500) DEFAULT NULL, `fs_confirm_email` TINYINT(1) NOT NULL DEFAULT 0, `fs_fields` TEXT NULL,
                    `fs_expires_at` DATETIME NULL, `fs_max_submissions` INT NULL,
                    `fs_submit_count` INT NOT NULL DEFAULT 0, `fs_created_by` VARCHAR(32) DEFAULT NULL,
                    `fs_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `fs_updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`fs_id`), UNIQUE KEY `uq_fs_token` (`fs_token`),
                    UNIQUE KEY `uq_fs_form` (`project_id`, `fs_form_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                $pdo->exec('CREATE TABLE IF NOT EXISTS `nu_form_share_log` (
                    `fl_id` INT NOT NULL AUTO_INCREMENT, `fl_fs_id` INT NOT NULL, `fl_record_id` VARCHAR(64) DEFAULT NULL,
                    `fl_ip_hash` VARCHAR(64) DEFAULT NULL, `fl_ua` VARCHAR(255) DEFAULT NULL,
                    `fl_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`fl_id`), KEY `idx_fl_share_time` (`fl_fs_id`, `fl_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            }
        } catch (Throwable $e) {
            error_log('[public form schema] ' . $e->getMessage());
        }
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ── Lookups ───────────────────────────────────────────────────────────────
    public static function shareByToken(NuDatabase $db, string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) return null;
        return $db->fetchOne('SELECT * FROM nu_form_shares WHERE fs_token = ?', [$token]) ?: null;
    }

    public static function shareForForm(NuDatabase $db, int $projectId, string $formCode): ?array
    {
        return $db->fetchOne('SELECT * FROM nu_form_shares WHERE project_id = ? AND fs_form_code = ?', [$projectId, $formCode]) ?: null;
    }

    public static function loadForm(NuDatabase $db, int $projectId, string $formCode): ?array
    {
        return $db->fetchOne(
            'SELECT * FROM nu_forms WHERE form_code = ? AND project_id = ? AND form_active = 1 LIMIT 1',
            [$formCode, $projectId]
        ) ?: null;
    }

    /** ok | disabled | expired | full | inactive */
    public static function state(array $share, ?array $form): string
    {
        if (!$form) return 'inactive';
        if (empty($share['fs_enabled'])) return 'disabled';
        if (!empty($share['fs_expires_at']) && strtotime((string)$share['fs_expires_at']) < time()) return 'expired';
        if (!empty($share['fs_max_submissions']) && (int)$share['fs_submit_count'] >= (int)$share['fs_max_submissions']) return 'full';
        return 'ok';
    }

    // ── Fields ────────────────────────────────────────────────────────────────
    private static function flatten(array $form): array
    {
        require_once __DIR__ . '/../api/_form_layout_helpers.php';
        $layout = json_decode((string)($form['form_layout'] ?? '[]'), true);
        return nu_flatten_layout_fields(is_array($layout) ? $layout : []);
    }

    private static function fname(array $f): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', (string)($f['name'] ?? ($f['fieldname'] ?? '')));
    }

    private static function ftype(array $f): string
    {
        $t = strtolower(trim((string)($f['type'] ?? ($f['fieldtype'] ?? 'text'))));
        return $t === '' ? 'text' : $t;
    }

    private static function options(array $f): array
    {
        $out = [];
        foreach ((is_array($f['options'] ?? null) ? $f['options'] : []) as $o) {
            if (is_array($o)) {
                $v = (string)($o['value'] ?? '');
                $out[] = ['value' => $v, 'label' => (string)($o['label'] ?? $v)];
            } elseif (is_scalar($o)) {
                $out[] = ['value' => (string)$o, 'label' => (string)$o];
            }
        }
        return $out;
    }

    /**
     * Every data field of the form, each flagged public-capable or not (with a reason).
     * @return array<int,array<string,mixed>>
     */
    public static function describeFields(array $form): array
    {
        $out = [];
        foreach (self::flatten($form) as $f) {
            $name = self::fname($f);
            if ($name === '') continue;
            $type = self::ftype($f);
            $reason = null;
            if (in_array($type, ['autonumber', 'uuid'], true)) {
                $reason = 'Filled in automatically';
            } elseif (!in_array($type, self::SUPPORTED, true)) {
                $reason = 'Field type "' . $type . '" is not available on public forms';
            } elseif (!empty($f['hidden']) || !empty($f['hidden_for_normal_users'])) {
                $reason = 'Hidden field';
            } elseif (!empty($f['readonly']) || !empty($f['server_readonly'])) {
                $reason = 'Read-only field';
            } elseif (!empty($f['is_fk'])) {
                $reason = 'Linked to a parent record';
            } elseif (in_array($type, ['select', 'radio', 'checkbox_group'], true)
                && (($f['options_source'] ?? ($f['source_type'] ?? 'manual')) !== 'manual' || !self::options($f))) {
                $reason = 'Choices come from a table or query, so they are not exposed publicly';
            }
            $out[] = [
                'name'        => $name,
                'label'       => (string)($f['label'] ?? ($f['fieldlabel'] ?? $name)),
                'type'        => $type,
                'required'    => !empty($f['required']),
                'placeholder' => (string)($f['placeholder'] ?? ''),
                'help'        => (string)($f['help_text'] ?? ($f['helptext'] ?? '')),
                'min'         => $f['min'] ?? null,
                'max'         => $f['max'] ?? null,
                'col'         => max(1, min(12, (int)($f['col'] ?? 12))),
                'options'     => in_array($type, ['select', 'radio', 'checkbox_group'], true) ? self::options($f) : [],
                'public'      => $reason === null,
                'reason'      => $reason,
                'raw'         => $f,
            ];
        }
        return $out;
    }

    /** Fields shown to the visitor: public-capable and selected by the share (all when none selected). */
    public static function visibleFields(array $form, array $share): array
    {
        $sel = json_decode((string)($share['fs_fields'] ?? ''), true);
        $sel = is_array($sel) && $sel ? array_flip(array_map('strval', $sel)) : null;
        $out = [];
        foreach (self::describeFields($form) as $d) {
            if (!$d['public']) continue;
            if ($sel !== null && !isset($sel[$d['name']])) continue;
            unset($d['raw']);
            $out[] = $d;
        }
        return $out;
    }

    // ── Anti-spam token (stateless) ───────────────────────────────────────────
    public static function signToken(array $share): string
    {
        $ts = (string)time();
        return $ts . '.' . hash_hmac('sha256', $ts . '|' . $share['fs_token'], (string)$share['fs_secret']);
    }

    /** Token must be genuine, at least 2s old (humans are slower than bots) and under 24h old. */
    public static function checkToken(array $share, string $tok): bool
    {
        if (!preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', $tok, $m)) return false;
        $age = time() - (int)$m[1];
        if ($age < 2 || $age > 86400) return false;
        return hash_equals(hash_hmac('sha256', $m[1] . '|' . $share['fs_token'], (string)$share['fs_secret']), $m[2]);
    }

    public static function ipHash(array $share): string
    {
        return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $share['fs_secret']);
    }

    /** 5 per IP per 10 minutes, 300 per link per hour. */
    public static function rateLimited(NuDatabase $db, array $share): bool
    {
        $now10 = date('Y-m-d H:i:s', time() - 600);
        $now60 = date('Y-m-d H:i:s', time() - 3600);
        $ip = (int)($db->fetchOne('SELECT COUNT(*) c FROM nu_form_share_log WHERE fl_fs_id = ? AND fl_ip_hash = ? AND fl_at > ?',
            [$share['fs_id'], self::ipHash($share), $now10])['c'] ?? 0);
        if ($ip >= 5) return true;
        $all = (int)($db->fetchOne('SELECT COUNT(*) c FROM nu_form_share_log WHERE fl_fs_id = ? AND fl_at > ?',
            [$share['fs_id'], $now60])['c'] ?? 0);
        return $all >= 300;
    }

    // ── Validation ────────────────────────────────────────────────────────────
    /**
     * @param array<int,array<string,mixed>> $fields visibleFields()
     * @return array{0:array<string,mixed>,1:array<string,string>} [clean values, errors by field]
     */
    public static function validate(array $fields, array $input): array
    {
        $clean = []; $err = [];
        foreach ($fields as $f) {
            $n = $f['name']; $t = $f['type'];
            $raw = $input[$n] ?? null;

            if ($t === 'checkbox') {
                $on = !empty($raw) && $raw !== '0' && $raw !== 'false';
                if ($f['required'] && !$on) { $err[$n] = 'Please tick this box to continue.'; continue; }
                $clean[$n] = $on ? 1 : 0;
                continue;
            }
            if ($t === 'checkbox_group') {
                $vals = array_values(array_filter(array_map('strval', is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw])), 'strlen'));
                $allowed = array_column($f['options'], 'value');
                if (array_diff($vals, $allowed)) { $err[$n] = 'Please choose from the listed options.'; continue; }
                if ($f['required'] && !$vals) { $err[$n] = 'Please choose at least one option.'; continue; }
                $clean[$n] = implode(',', $vals);
                continue;
            }

            $v = is_array($raw) ? '' : trim((string)($raw ?? ''));
            if ($v === '') {
                if ($f['required']) $err[$n] = 'This field is required.';
                else $clean[$n] = null;
                continue;
            }
            switch ($t) {
                case 'text': case 'tel': case 'phone':
                    if (mb_strlen($v) > 1000) { $err[$n] = 'Too long (max 1000 characters).'; continue 2; }
                    if (($t === 'tel' || $t === 'phone') && !preg_match('/^[0-9+()\-.\s]{5,30}$/', $v)) { $err[$n] = 'Enter a valid phone number.'; continue 2; }
                    break;
                case 'textarea':
                    if (mb_strlen($v) > 10000) { $err[$n] = 'Too long (max 10000 characters).'; continue 2; }
                    break;
                case 'email':
                    if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 254) { $err[$n] = 'Enter a valid email address.'; continue 2; }
                    break;
                case 'url':
                    if (!preg_match('#^https?://#i', $v) || !filter_var($v, FILTER_VALIDATE_URL) || mb_strlen($v) > 500) { $err[$n] = 'Enter a valid web address (https://...).'; continue 2; }
                    break;
                case 'number':
                    if (!is_numeric($v)) { $err[$n] = 'Enter a number.'; continue 2; }
                    if ($f['min'] !== null && $f['min'] !== '' && (float)$v < (float)$f['min']) { $err[$n] = 'Must be at least ' . $f['min'] . '.'; continue 2; }
                    if ($f['max'] !== null && $f['max'] !== '' && (float)$v > (float)$f['max']) { $err[$n] = 'Must be at most ' . $f['max'] . '.'; continue 2; }
                    $v = $v + 0;
                    break;
                case 'date':
                    $d = DateTime::createFromFormat('Y-m-d', $v);
                    if (!$d || $d->format('Y-m-d') !== $v) { $err[$n] = 'Enter a valid date.'; continue 2; }
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) { $err[$n] = 'Enter a valid time.'; continue 2; }
                    break;
                case 'datetime':
                    $v = str_replace('T', ' ', $v);
                    $ts = strtotime($v);
                    if ($ts === false) { $err[$n] = 'Enter a valid date and time.'; continue 2; }
                    $v = date('Y-m-d H:i:s', $ts);
                    break;
                case 'select': case 'radio':
                    if (!in_array($v, array_column($f['options'], 'value'), true)) { $err[$n] = 'Please choose one of the listed options.'; continue 2; }
                    break;
            }
            $clean[$n] = $v;
        }
        return [$clean, $err];
    }

    // ── Insert ────────────────────────────────────────────────────────────────
    private static function columns(NuDatabase $db, string $table): array
    {
        try {
            if ($db->getDriverName() === 'sqlite') {
                $rows = $db->getPdo()->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);
                return array_map(fn($r) => ['name' => $r['name'], 'type' => strtolower((string)$r['type']), 'pk' => !empty($r['pk'])], $rows);
            }
            $rows = $db->getPdo()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn($r) => ['name' => $r['Field'], 'type' => strtolower((string)$r['Type']), 'pk' => ($r['Key'] ?? '') === 'PRI'], $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function autonumber(NuDatabase $db, array $field, array $data, string $formCode): string
    {
        $pdo = $db->getPdo();
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS nu_sequence_counters (seq_code VARCHAR(100) NOT NULL PRIMARY KEY, seq_value INTEGER NOT NULL DEFAULT 0)');
        } catch (Throwable $e) {}
        $seq = trim((string)($field['sequence_code'] ?? ''));
        if ($seq === '') $seq = $formCode . '_' . self::fname($field);
        $seq = preg_replace('/[^a-zA-Z0-9_]/', '_', $seq);
        $pattern = function (string $p) use ($data): string {
            return preg_replace_callback('/\{+([a-zA-Z0-9_]+)\}+/', function ($m) use ($data) {
                $k = strtolower($m[1]);
                if ($k === 'year') return date('Y');
                if ($k === 'month') return date('m');
                if ($k === 'day') return date('d');
                return isset($data[$m[1]]) ? trim((string)$data[$m[1]]) : '';
            }, $p);
        };
        $prefix = $pattern((string)($field['prefix_pattern'] ?? ''));
        $suffix = $pattern((string)($field['suffix_pattern'] ?? ''));
        if (!$db->fetchOne('SELECT seq_value FROM nu_sequence_counters WHERE seq_code = ?', [$seq])) {
            try { $db->query('INSERT INTO nu_sequence_counters (seq_code, seq_value) VALUES (?, 0)', [$seq]); } catch (Throwable $e) {}
        }
        $db->query('UPDATE nu_sequence_counters SET seq_value = seq_value + 1 WHERE seq_code = ?', [$seq]);
        $n = (int)($db->fetchOne('SELECT seq_value FROM nu_sequence_counters WHERE seq_code = ?', [$seq])['seq_value'] ?? 1);
        $pad = isset($field['padding_length']) ? max(0, (int)$field['padding_length']) : 6;
        return $prefix . str_pad((string)max(1, $n), $pad, '0', STR_PAD_LEFT) . $suffix;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Store a validated submission as a new record in the form's table.
     * @return array{id:string,reference:string}
     */
    public static function insertRecord(NuDatabase $db, array $share, array $form, array $clean): array
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($form['form_table'] ?? ''));
        if ($table === '') throw new RuntimeException('This form has no table.');
        $cols = self::columns($db, $table);
        if (!$cols) throw new RuntimeException('The form table is not available.');
        $byName = [];
        foreach ($cols as $c) $byName[$c['name']] = $c;
        $pk = null;
        foreach ($cols as $c) if ($c['pk']) { $pk = $c; break; }

        $save = [];
        foreach ($clean as $k => $v) {
            if (isset($byName[$k]) && (!$pk || $k !== $pk['name'])) $save[$k] = $v;
        }
        // Generated fields (autonumber / uuid) defined on the form
        foreach (self::describeFields($form) as $d) {
            if (!isset($byName[$d['name']])) continue;
            if ($d['type'] === 'autonumber') $save[$d['name']] = self::autonumber($db, $d['raw'], $clean, (string)$form['form_code']);
            elseif ($d['type'] === 'uuid') $save[$d['name']] = self::uuid();
        }
        $now = date('Y-m-d H:i:s');
        if (isset($byName['created_at'])) $save['created_at'] = $now;
        if (isset($byName['updated_at'])) $save['updated_at'] = $now;

        $pkString = false;
        if ($pk) {
            $pkString = (bool)preg_match('/char|text|uuid/', $pk['type']);
            if ($pkString && !isset($save[$pk['name']])) $save[$pk['name']] = self::uuid();
        }
        if (!$save) throw new RuntimeException('Nothing to save.');
        $names = array_keys($save);
        $db->query(
            'INSERT INTO `' . $table . '` (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')',
            array_values($save)
        );
        $id = ($pk && $pkString) ? (string)$save[$pk['name']] : (string)$db->lastInsertId();

        $reference = $id;
        foreach (self::describeFields($form) as $d) {
            if ($d['type'] === 'autonumber' && isset($save[$d['name']])) { $reference = (string)$save[$d['name']]; break; }
        }
        return ['id' => $id, 'reference' => $reference, 'table' => $table, 'saved' => $save];
    }

    /** Full submission pipeline. Returns ['ok'=>bool, 'errors'=>[...], 'message'=>..., 'reference'=>...]. */
    public static function submit(NuDatabase $db, array $share, array $form, array $input): array
    {
        $state = self::state($share, $form);
        if ($state !== 'ok') return ['ok' => false, 'status' => 410, 'message' => self::stateMessage($state)];

        // Bots: filled honeypot gets a fake success and nothing is stored.
        if (trim((string)($input['website'] ?? '')) !== '') {
            return ['ok' => true, 'reference' => '', 'message' => self::successMessage($share), 'redirect' => (string)($share['fs_redirect_url'] ?? '')];
        }
        if (!self::checkToken($share, (string)($input['_t'] ?? ''))) {
            return ['ok' => false, 'status' => 400, 'message' => 'Your session expired or the form was submitted too quickly. Please try again.', 'retry' => true];
        }
        if (self::rateLimited($db, $share)) {
            return ['ok' => false, 'status' => 429, 'message' => 'Too many submissions. Please wait a few minutes and try again.'];
        }

        $fields = self::visibleFields($form, $share);
        [$clean, $errors] = self::validate($fields, $input);
        if ($errors) return ['ok' => false, 'status' => 422, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors];

        // Cap check again inside the write path so parallel requests cannot overshoot.
        if (!empty($share['fs_max_submissions'])) {
            $claim = $db->query(
                'UPDATE nu_form_shares SET fs_submit_count = fs_submit_count + 1 WHERE fs_id = ? AND fs_submit_count < ?',
                [$share['fs_id'], (int)$share['fs_max_submissions']]
            );
            if ($claim->rowCount() < 1) return ['ok' => false, 'status' => 410, 'message' => self::stateMessage('full')];
        } else {
            $db->query('UPDATE nu_form_shares SET fs_submit_count = fs_submit_count + 1 WHERE fs_id = ?', [$share['fs_id']]);
        }

        try {
            $rec = self::insertRecord($db, $share, $form, $clean);
        } catch (Throwable $e) {
            $db->query('UPDATE nu_form_shares SET fs_submit_count = CASE WHEN fs_submit_count > 0 THEN fs_submit_count - 1 ELSE 0 END WHERE fs_id = ?', [$share['fs_id']]);
            error_log('[public form insert] ' . $e->getMessage());
            return ['ok' => false, 'status' => 500, 'message' => 'Sorry, we could not save your request. Please try again later.'];
        }

        try {
            $db->query('INSERT INTO nu_form_share_log (fl_fs_id, fl_record_id, fl_ip_hash, fl_ua, fl_at) VALUES (?,?,?,?,?)',
                [$share['fs_id'], $rec['id'], self::ipHash($share), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {}

        self::afterInsert($db, $share, $form, $fields, $clean, $rec);

        return [
            'ok' => true,
            'reference' => $rec['reference'],
            'message' => self::successMessage($share),
            'redirect' => (string)($share['fs_redirect_url'] ?? ''),
        ];
    }

    private static function afterInsert(NuDatabase $db, array $share, array $form, array $fields, array $clean, array $rec): void
    {
        // Start a workflow bound to this form (same rule as the signed-in save path).
        try {
            require_once __DIR__ . '/Workflow.php';
            $wf = $db->fetchOne('SELECT wf_id FROM nu_workflows WHERE LOWER(wf_form_code) = LOWER(?) AND wf_active = 1 LIMIT 1', [$form['form_code']]);
            if ($wf) (new WorkflowEngine())->start((int)$wf['wf_id'], 0, $rec['table'], (string)$rec['id'], ['source' => 'public_form']);
        } catch (Throwable $e) { error_log('[public form workflow] ' . $e->getMessage()); }

        // Outgoing webhooks
        try {
            require_once __DIR__ . '/WebhookSender.php';
            NuWebhookSender::trigger('form_insert', [
                'table' => $rec['table'], 'record_id' => $rec['id'], 'source' => 'public_form',
                'data' => array_merge($rec['saved'], ['id' => $rec['id']]),
            ]);
        } catch (Throwable $e) { error_log('[public form webhook] ' . $e->getMessage()); }

        // Emails
        try {
            require_once __DIR__ . '/Emailer.php';
            $mailer = new NuEmailer();
            $title = trim((string)($share['fs_title'] ?? '')) ?: (string)$form['form_name'];
            $lines = [];
            $confirmTo = '';
            foreach ($fields as $f) {
                $v = $clean[$f['name']] ?? '';
                $lines[] = $f['label'] . ': ' . (is_scalar($v) ? (string)$v : '');
                if ($f['type'] === 'email' && $confirmTo === '' && !empty($v)) $confirmTo = (string)$v;
            }
            $body = "New submission for \"{$title}\"\nReference: {$rec['reference']}\n\n" . implode("\n", $lines) . "\n";
            foreach (preg_split('/[,;\s]+/', (string)($share['fs_notify_email'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $to) {
                if (filter_var($to, FILTER_VALIDATE_EMAIL)) $mailer->send($to, 'New request: ' . $title, $body);
            }
            if (!empty($share['fs_confirm_email']) && $confirmTo !== '' && !preg_match('/[\r\n]/', $confirmTo)) {
                $mailer->send($confirmTo, 'We received your request: ' . $title,
                    "Thank you. We have received your request.\nReference: {$rec['reference']}\n\n" . implode("\n", $lines) . "\n");
            }
        } catch (Throwable $e) { error_log('[public form email] ' . $e->getMessage()); }
    }

    public static function successMessage(array $share): string
    {
        $m = trim((string)($share['fs_success_message'] ?? ''));
        return $m !== '' ? $m : 'Thank you! Your request has been submitted.';
    }

    public static function stateMessage(string $state): string
    {
        switch ($state) {
            case 'expired': return 'This link has expired.';
            case 'full':    return 'This form is no longer accepting responses.';
            case 'disabled': return 'This form is not currently accepting responses.';
            default:        return 'This form is not available.';
        }
    }
}
