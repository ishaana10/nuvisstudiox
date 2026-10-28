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
        // Columns added after the first release (look & per-field settings)
        foreach (['fs_theme', 'fs_field_cfg'] as $col) {
            try {
                $have = array_column(self::columns($db, 'nu_form_shares'), 'name');
                if ($have && !in_array($col, $have, true)) $pdo->exec('ALTER TABLE `nu_form_shares` ADD COLUMN `' . $col . '` TEXT NULL');
            } catch (Throwable $e) {
                error_log('[public form schema] ' . $e->getMessage());
            }
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
    public static function describeFields(array $form, ?array $share = null): array
    {
        $cfg = $share ? self::fieldCfg($share) : [];
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
            $o = $cfg[$name] ?? [];
            $out[] = [
                'name'        => $name,
                'label'       => (isset($o['label']) && $o['label'] !== '') ? $o['label'] : (string)($f['label'] ?? ($f['fieldlabel'] ?? $name)),
                'type'        => $type,
                'required'    => array_key_exists('required', $o) && $o['required'] !== null ? (bool)$o['required'] : !empty($f['required']),
                'placeholder' => isset($o['placeholder']) && $o['placeholder'] !== '' ? $o['placeholder'] : (string)($f['placeholder'] ?? ''),
                'help'        => isset($o['help']) && $o['help'] !== '' ? $o['help'] : (string)($f['help_text'] ?? ($f['helptext'] ?? '')),
                'default'     => (string)($o['default'] ?? ''),
                'min'         => $f['min'] ?? null,
                'max'         => $f['max'] ?? null,
                'col'         => !empty($o['col']) ? max(1, min(12, (int)$o['col'])) : max(1, min(12, (int)($f['col'] ?? 12))),
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
        foreach (self::describeFields($form, $share) as $d) {
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
    /** @return array<int,array{name:string,type:string,pk:bool,nullable:bool,has_default:bool,auto:bool}> */
    private static function columns(NuDatabase $db, string $table): array
    {
        try {
            if ($db->getDriverName() === 'sqlite') {
                $rows = $db->getPdo()->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(PDO::FETCH_ASSOC);
                return array_map(function ($r) {
                    $type = strtolower((string)$r['type']);
                    return ['name' => $r['name'], 'type' => $type, 'pk' => !empty($r['pk']),
                        'nullable' => empty($r['notnull']) && empty($r['pk']), 'has_default' => $r['dflt_value'] !== null,
                        'auto' => !empty($r['pk']) && $type === 'integer'];
                }, $rows);
            }
            $rows = $db->getPdo()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn($r) => [
                'name' => $r['Field'], 'type' => strtolower((string)$r['Type']), 'pk' => ($r['Key'] ?? '') === 'PRI',
                'nullable' => strtoupper((string)($r['Null'] ?? 'YES')) === 'YES', 'has_default' => $r['Default'] !== null,
                'auto' => stripos((string)($r['Extra'] ?? ''), 'auto_increment') !== false
                    || stripos((string)($r['Extra'] ?? ''), 'GENERATED') !== false,
            ], $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** A harmless value for a NOT NULL column that has no default and was left empty. */
    private static function zeroValue(string $type)
    {
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|integer|decimal|numeric|float|double|real|bit|year)/', $type)) return 0;
        if (strpos($type, 'datetime') === 0 || strpos($type, 'timestamp') === 0) return date('Y-m-d H:i:s');
        if (strpos($type, 'date') === 0) return date('Y-m-d');
        if (strpos($type, 'time') === 0) return '00:00:00';
        if (strpos($type, 'enum') === 0 && preg_match("/^enum\('((?:[^']|'')*)'/", $type, $m)) return str_replace("''", "'", $m[1]);
        return '';
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
            if (!isset($byName[$k]) || ($pk && $k === $pk['name'])) continue;
            $c = $byName[$k];
            if ($v === null) {
                // Left empty: let the database use NULL / its default; only fill NOT NULL columns that have neither.
                if ($c['nullable'] || $c['has_default']) continue;
                $v = self::zeroValue($c['type']);
            }
            $save[$k] = $v;
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

        // Other NOT NULL columns the form does not collect (and that have no default) would make MySQL refuse the row.
        foreach ($cols as $c) {
            if ($c['pk'] || $c['nullable'] || $c['has_default'] || $c['auto'] || array_key_exists($c['name'], $save)) continue;
            $save[$c['name']] = self::zeroValue($c['type']);
        }

        $pkString = false;
        if ($pk) {
            $pkString = (bool)preg_match('/char|text|uuid/', $pk['type']);
            if (!isset($save[$pk['name']]) && !$pk['auto'] && !$pk['has_default']) {
                if ($pkString) {
                    $save[$pk['name']] = self::uuid();
                } else {
                    $save[$pk['name']] = 1 + (int)($db->fetchOne('SELECT COALESCE(MAX(`' . $pk['name'] . '`), 0) AS m FROM `' . $table . '`')['m'] ?? 0);
                }
            }
        }
        if (!$save) throw new RuntimeException('Nothing to save.');
        $names = array_keys($save);
        $db->query(
            'INSERT INTO `' . $table . '` (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')',
            array_values($save)
        );
        $id = ($pk && isset($save[$pk['name']])) ? (string)$save[$pk['name']] : (string)$db->lastInsertId();

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
            return ['ok' => false, 'status' => 500, 'message' => 'Sorry, we could not save your request. Please try again later.', 'detail' => $e->getMessage()];
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

    // ── Per-field overrides ───────────────────────────────────────────────────
    /** @return array<string,array<string,mixed>> */
    public static function fieldCfg(array $share): array
    {
        $c = json_decode((string)($share['fs_field_cfg'] ?? ''), true);
        return is_array($c) ? $c : [];
    }

    /** Validate/clean the per-field settings coming from the admin dialog. */
    public static function cleanFieldCfg($raw, array $publicNames): array
    {
        $out = [];
        if (!is_array($raw)) return $out;
        foreach ($raw as $name => $o) {
            if (!in_array((string)$name, $publicNames, true) || !is_array($o)) continue;
            $c = [];
            foreach (['label' => 200, 'placeholder' => 200, 'help' => 500, 'default' => 500] as $k => $max) {
                $v = trim((string)($o[$k] ?? ''));
                if ($v !== '') $c[$k] = mb_substr($v, 0, $max);
            }
            if (isset($o['required']) && $o['required'] !== '' && $o['required'] !== null) $c['required'] = !empty($o['required']) && $o['required'] !== 'false' && $o['required'] !== '0';
            $col = (int)($o['col'] ?? 0);
            if ($col >= 1 && $col <= 12) $c['col'] = $col;
            if ($c) $out[(string)$name] = $c;
        }
        return $out;
    }

    // ── Look & feel ───────────────────────────────────────────────────────────
    public const THEME_DEFAULTS = [
        'width' => 'medium', 'mode' => 'auto', 'accent' => '', 'bg' => '', 'card_bg' => '', 'text' => '',
        'radius' => 'md', 'font' => 'system', 'density' => 'comfortable', 'title_align' => 'left',
        'show_title' => 1, 'logo_url' => '', 'logo_height' => 48, 'button_align' => 'left', 'button_full' => 0,
        'section_style' => 'boxed', 'tab_style' => 'underline', 'footer_text' => '', 'hide_footer' => 0, 'custom_css' => '',
    ];

    public static function theme(array $share): array
    {
        $raw = json_decode((string)($share['fs_theme'] ?? ''), true);
        return self::cleanTheme(is_array($raw) ? $raw : []);
    }

    public static function cleanTheme(array $in): array
    {
        $t = self::THEME_DEFAULTS;
        $pick = function (string $k, array $allowed) use (&$t, $in) {
            if (isset($in[$k]) && in_array((string)$in[$k], $allowed, true)) $t[$k] = (string)$in[$k];
        };
        $pick('width', ['narrow', 'medium', 'wide', 'full']);
        $pick('mode', ['auto', 'light', 'dark']);
        $pick('radius', ['none', 'sm', 'md', 'lg']);
        $pick('font', ['system', 'serif', 'rounded', 'mono']);
        $pick('density', ['comfortable', 'compact']);
        $pick('title_align', ['left', 'center']);
        $pick('button_align', ['left', 'center', 'right']);
        $pick('section_style', ['boxed', 'flat', 'plain']);
        $pick('tab_style', ['underline', 'pills']);
        foreach (['accent', 'bg', 'card_bg', 'text'] as $k) {
            $v = trim((string)($in[$k] ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $v)) $t[$k] = strtolower($v);
        }
        foreach (['show_title', 'button_full', 'hide_footer'] as $k) if (isset($in[$k])) $t[$k] = !empty($in[$k]) && $in[$k] !== '0' ? 1 : 0;
        $logo = trim((string)($in['logo_url'] ?? ''));
        if ($logo !== '' && preg_match('#^https://#i', $logo) && filter_var($logo, FILTER_VALIDATE_URL) && mb_strlen($logo) <= 500) $t['logo_url'] = $logo;
        $lh = (int)($in['logo_height'] ?? 48);
        $t['logo_height'] = max(16, min(160, $lh ?: 48));
        $t['footer_text'] = mb_substr(trim((string)($in['footer_text'] ?? '')), 0, 300);
        $css = (string)($in['custom_css'] ?? '');
        $css = preg_replace('/@import[^;]*;?/i', '', $css);
        $css = str_replace(['<', '>', '\\00003c', '\\3c '], '', $css);
        $t['custom_css'] = mb_substr(trim($css), 0, 8000);
        return $t;
    }

    /** CSS custom properties + a few structural rules derived from the theme. */
    public static function themeCss(array $t): string
    {
        $w = ['narrow' => '520px', 'medium' => '680px', 'wide' => '920px', 'full' => '1280px'][$t['width']];
        $r = ['none' => ['2px', '2px'], 'sm' => ['8px', '6px'], 'md' => ['16px', '10px'], 'lg' => ['26px', '14px']][$t['radius']];
        $font = [
            'system'  => 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif',
            'serif'   => 'Georgia,"Times New Roman",serif',
            'rounded' => 'ui-rounded,"SF Pro Rounded","Nunito","Segoe UI",system-ui,sans-serif',
            'mono'    => 'ui-monospace,Menlo,Consolas,monospace',
        ][$t['font']];
        $vars = '--w:' . $w . ';--r-card:' . $r[0] . ';--r-in:' . $r[1] . ';--font:' . $font . ';'
              . '--gap:' . ($t['density'] === 'compact' ? '10px' : '16px') . ';--pad:' . ($t['density'] === 'compact' ? '18px' : '28px') . ';--in-h:' . ($t['density'] === 'compact' ? '38px' : '44px') . ';';
        $over = '';
        if ($t['accent'] !== '') $over .= '--primary:' . $t['accent'] . ';--primary-d:color-mix(in srgb,' . $t['accent'] . ' 82%,#000);';
        if ($t['bg'] !== '')      $over .= '--bg:' . $t['bg'] . ';';
        if ($t['card_bg'] !== '') $over .= '--card:' . $t['card_bg'] . ';';
        if ($t['text'] !== '')    $over .= '--text:' . $t['text'] . ';';
        return ':root{' . $vars . '}' . ($over !== '' ? 'html[data-mode]{' . $over . '}' : '');
    }

    // ── Layout (sections, groups, tabs, rows) ─────────────────────────────────
    /**
     * Turn the stored form layout into a tree the public page can render, keeping only the
     * fields the visitor may fill in. Empty containers are dropped.
     *
     * Node kinds: section|group {label,collapsible,collapsed,children}, tabs {tabs:[{label,children}]},
     *             row {items:[field...]}, field {name}, heading {label,level}, html {html}, divider.
     * @param array<int,array<string,mixed>> $visible visibleFields()
     */
    public static function layoutTree(array $form, array $visible): array
    {
        $layout = json_decode((string)($form['form_layout'] ?? '[]'), true);
        $layout = is_array($layout) ? $layout : [];
        if (isset($layout['items']) && is_array($layout['items'])) $layout = $layout['items'];
        $known = [];
        foreach ($visible as $v) $known[$v['name']] = true;
        $used = [];

        $leafName = function (array $it): string {
            return preg_replace('/[^a-zA-Z0-9_]/', '', (string)($it['name'] ?? ($it['fieldname'] ?? '')));
        };
        $rowsToNodes = null;
        $walk = null;

        // Pack loose consecutive fields into rows so that column widths sit side by side.
        $pack = function (array $nodes): array {
            $out = []; $buf = [];
            $flush = function () use (&$out, &$buf) { if ($buf) { $out[] = ['kind' => 'row', 'items' => $buf]; $buf = []; } };
            foreach ($nodes as $n) {
                if ($n['kind'] === 'field') { $buf[] = $n; continue; }
                $flush(); $out[] = $n;
            }
            $flush();
            return $out;
        };

        $rowsToNodes = function (array $rows) use (&$walk, $pack): array {
            $nodes = [];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $rt = strtolower(trim((string)($row['type'] ?? 'row')));
                if ($rt === 'group' || $rt === 'section') { $nodes = array_merge($nodes, $walk([$row])); continue; }
                $items = $walk($row['fields'] ?? ($row['children'] ?? []));
                $fields = array_values(array_filter($items, fn($n) => $n['kind'] === 'field'));
                $other  = array_values(array_filter($items, fn($n) => $n['kind'] !== 'field'));
                if ($fields) $nodes[] = ['kind' => 'row', 'items' => $fields];
                foreach ($other as $o) $nodes[] = $o;
            }
            return $nodes;
        };

        $walk = function ($items) use (&$walk, &$rowsToNodes, $pack, $known, &$used, $leafName): array {
            $out = [];
            if (!is_array($items)) return $out;
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $type = strtolower(trim((string)($it['type'] ?? '')));
                if ($type === 'tab') {
                    $tabs = [];
                    foreach (($it['tabs'] ?? []) as $i => $tab) {
                        if (!is_array($tab)) continue;
                        $kids = $rowsToNodes($tab['rows'] ?? []);
                        if (!empty($tab['children'])) $kids = array_merge($kids, $walk($tab['children']));
                        $kids = self::pruneNodes($pack($kids));
                        if ($kids) $tabs[] = ['label' => (string)($tab['name'] ?? ($tab['label'] ?? ('Tab ' . ($i + 1)))), 'children' => $kids];
                    }
                    if ($tabs) $out[] = ['kind' => 'tabs', 'tabs' => $tabs];
                } elseif ($type === 'section' || $type === 'group' || $type === 'fieldset') {
                    $kids = $walk($it['children'] ?? []);
                    if (!empty($it['rows'])) $kids = array_merge($kids, $rowsToNodes($it['rows']));
                    $kids = self::pruneNodes($pack($kids));
                    if ($kids) $out[] = [
                        'kind' => $type === 'section' ? 'section' : 'group',
                        'label' => (string)($it['label'] ?? ($it['legend'] ?? '')),
                        'collapsible' => !empty($it['collapsible']), 'collapsed' => !empty($it['collapsed']),
                        'children' => $kids,
                    ];
                } elseif ($type === 'row' || (isset($it['fields']) && is_array($it['fields']) && $type === '')) {
                    $items2 = $walk($it['fields'] ?? ($it['children'] ?? []));
                    foreach ($items2 as $n2) $out[] = $n2;       // fields go on to be packed by the parent
                    if ($items2 && end($items2)['kind'] === 'field') $out[] = ['kind' => '_break'];
                } elseif ($type === 'heading') {
                    $lab = trim((string)($it['label'] ?? ($it['text'] ?? ($it['content'] ?? ''))));
                    if ($lab !== '') $out[] = ['kind' => 'heading', 'label' => $lab, 'level' => max(2, min(4, (int)($it['level'] ?? 3)))];
                } elseif ($type === 'html' || $type === 'content') {
                    $raw = (string)($it['content'] ?? ($it['html'] ?? ($it['value'] ?? ($it['label'] ?? ''))));
                    $h = self::sanitizeHtml($raw);
                    if (trim(strip_tags($h)) !== '') $out[] = ['kind' => 'html', 'html' => $h];
                } elseif ($type === 'divider') {
                    $out[] = ['kind' => 'divider'];
                } elseif (in_array($type, ['subform', 'button', '_form_style'], true)) {
                    continue;
                } else {
                    $n = $leafName($it);
                    if ($n !== '' && isset($known[$n]) && !isset($used[$n])) { $used[$n] = true; $out[] = ['kind' => 'field', 'name' => $n]; }
                }
            }
            return $out;
        };

        // _break markers keep explicit rows apart when packing
        $packWithBreaks = function (array $nodes) use ($pack): array {
            $segments = []; $cur = [];
            foreach ($nodes as $n) { if ($n['kind'] === '_break') { $segments[] = $cur; $cur = []; } else $cur[] = $n; }
            $segments[] = $cur;
            $out = [];
            foreach ($segments as $seg) foreach ($pack($seg) as $n) $out[] = $n;
            return $out;
        };

        $tree = self::pruneNodes($packWithBreaks($walk($layout)));

        // Selected fields that the layout did not place (e.g. flat layouts) still get shown.
        $rest = [];
        foreach ($visible as $v) if (!isset($used[$v['name']])) $rest[] = ['kind' => 'field', 'name' => $v['name']];
        if ($rest) $tree[] = ['kind' => 'row', 'items' => $rest];
        return $tree;
    }

    private static function pruneNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if ($n['kind'] === '_break') continue;
            if (isset($n['children']) && !$n['children']) continue;
            if ($n['kind'] === 'row' && empty($n['items'])) continue;
            $out[] = $n;
        }
        return $out;
    }

    /** Allow simple formatting only: no scripts, styles, event handlers or odd URLs. */
    public static function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') return '';
        $allowed = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'span', 'div', 'small', 'blockquote', 'hr', 'sub', 'sup'];
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="r">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $dom->getElementById('r');
        if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $clean = function (DOMNode $node) use (&$clean, $allowed, $dom) {
            foreach (iterator_to_array($node->childNodes) as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower($c->nodeName);
                    if (!in_array($tag, $allowed, true)) {
                        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base'], true)) { $node->removeChild($c); continue; }
                        $clean($c);
                        while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                        $node->removeChild($c);
                        continue;
                    }
                    foreach (iterator_to_array($c->attributes) as $a) {
                        $keep = $tag === 'a' && in_array($a->nodeName, ['href', 'title'], true);
                        if ($keep && $a->nodeName === 'href' && !preg_match('#^(https?:|mailto:|tel:|/|\#)#i', trim($a->nodeValue))) $keep = false;
                        if (!$keep) $c->removeAttribute($a->nodeName);
                    }
                    if ($tag === 'a') { $c->setAttribute('rel', 'noopener noreferrer nofollow'); $c->setAttribute('target', '_blank'); }
                    $clean($c);
                } elseif ($c->nodeType !== XML_TEXT_NODE) {
                    $node->removeChild($c);
                }
            }
        };
        $clean($root);
        $out = '';
        foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
        return $out;
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
