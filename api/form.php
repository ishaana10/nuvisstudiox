<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        $msg = $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'];
        error_log('[NuForm FATAL] ' . $msg);
        echo json_encode(['success' => false, 'error' => $msg]);
    }
});

function nu_json($arr, $status = 200) {
    http_response_code($status);
    while (ob_get_level()) {
        $buffer = ob_get_clean();
        if (trim($buffer) !== '') $arr['_output'] = $buffer;
    }
    echo json_encode($arr);
    exit;
}

function nu_log($message, $context = '') {
    $prefix = '[NuForm' . ($context !== '' ? ':' . $context : '') . '] ';
    error_log($prefix . $message);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/_form_layout_helpers.php';
if (file_exists(__DIR__ . '/../core/Auth.php')) require_once __DIR__ . '/../core/Auth.php';

function nu_db() {
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];
    if (class_exists('NuDatabase')) { $c = NuDatabase::getConnection(); if ($c instanceof PDO) return $c; }
    if (class_exists('Database')) {
        foreach (['getConnection','getInstance','connect'] as $m) {
            if (!method_exists('Database', $m)) continue;
            $c = Database::$m();
            if ($c instanceof PDO) return $c;
            if (is_object($c) && method_exists($c,'getPdo') && ($p=$c->getPdo()) instanceof PDO) return $p;
            if (is_object($c) && method_exists($c,'getConnection') && ($p=$c->getConnection()) instanceof PDO) return $p;
        }
    }
    throw new Exception('Database connection not available');
}

function nu_q($sql, $params = []) {
    $stmt = nu_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function nu_request_json() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function nu_safe_ident($value) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$value);
}

function nu_table_exists($table) {
    try { $stmt = nu_q("SHOW TABLES LIKE ?", [$table]); return (bool)$stmt->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function nu_get_table_columns($table) {
    static $cache = [];
    if (isset($GLOBALS['_nu_col_cache'][$table])) {
        $cache[$table] = $GLOBALS['_nu_col_cache'][$table];
        unset($GLOBALS['_nu_col_cache'][$table]);
    }
    if (isset($cache[$table])) return $cache[$table];
    try {
        $rows = nu_q("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $row) $cols[$row['Field']] = true;
        $cache[$table] = $cols;
        return $cols;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function nu_filter_save_to_columns($save, $table, $pk) {
    $cols = nu_get_table_columns($table);
    if (empty($cols)) return $save;
    $out = [];
    foreach ($save as $col => $val) {
        if ($col === $pk || isset($cols[$col])) $out[$col] = $val;
    }
    return $out;
}

function nu_coerce_save_value($value) {
    if (is_array($value)) {
        return implode(',', array_map('strval', $value));
    }
    return $value;
}

function nu_form_table_name() {
    if (nu_table_exists('nu_forms')) return 'nu_forms';
    if (nu_table_exists('nuforms')) return 'nuforms';
    return 'nu_forms';
}

function nu_form_columns() {
    $table = nu_form_table_name();
    if ($table === 'nuforms') {
        return ['id'=>'id','name'=>'formname','code'=>'formcode','table'=>'formtable',
            'layout'=>'formlayout','active'=>'formactive','custom_js'=>'formcustomjs',
            'custom_php'=>'formcustomphp','custom_php_after'=>'formcustomphpafter','custom_css'=>'formcustomcss','browse_sql'=>'browsesql',
            'browse_columns'=>'browsecolumns','browse_search_enabled'=>'browsesearchenabled',
            'browse_search_placeholder'=>'browsesearchplaceholder','browse_search_fields'=>'browsesearchfields',
            'browse_page_size'=>'browsepagesize','browse_default_sort'=>'browsedefaultsort',
            'pk_type'=>'form_pk_type','table_mode'=>'form_table_mode',
            'type'=>'form_type','display_mode'=>'browse_display_mode',
            'js_before_save'=>'formjsbeforesave','js_after_save'=>'formjsaftersave',
            'browse_php'=>'browsephp', 'browse_conditions'=>'browseconditions',
            'browse_layout'=>'browselayout', 'browse_delete_enabled'=>'browsedeleteenabled'];
    }
    return ['id'=>'form_id','name'=>'form_name','code'=>'form_code','table'=>'form_table',
        'layout'=>'form_layout','active'=>'form_active','custom_js'=>'form_custom_js',
        'custom_php'=>'form_custom_php','custom_php_after'=>'form_custom_php_after','custom_css'=>'form_custom_css','browse_sql'=>'browse_sql',
        'browse_columns'=>'browse_columns','browse_search_enabled'=>'browse_search_enabled',
        'browse_search_placeholder'=>'browse_search_placeholder','browse_search_fields'=>'browse_search_fields',
        'browse_page_size'=>'browse_page_size','browse_default_sort'=>'browse_default_sort',
        'pk_type'=>'form_pk_type','table_mode'=>'form_table_mode',
        'type'=>'form_type','display_mode'=>'browse_display_mode',
        'js_before_save'=>'form_js_before_save','js_after_save'=>'form_js_after_save',
        'browse_php'=>'browse_php', 'browse_conditions'=>'browse_conditions',
        'browse_layout'=>'browse_layout', 'browse_delete_enabled'=>'browse_delete_enabled'];
}

function nu_get_form($code) {
    $table = nu_form_table_name();
    $c = nu_form_columns();
    $pid = class_exists('ProjectContext') ? ProjectContext::getId() : 1;

    $stmt = nu_q(
        "SELECT * FROM `{$table}` WHERE `{$c['code']}` = ? AND project_id = ? AND `{$c['active']}` = 1 LIMIT 1",
        [$code, $pid]
    );
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($form) return $form;

    $stmt = nu_q(
        "SELECT * FROM `{$table}` WHERE `{$c['code']}` = ? AND project_id = ? LIMIT 1",
        [$code, $pid]
    );
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($form) return $form;

    // Fallback search without project_id if not found in current project (e.g. system forms)
    $stmt = nu_q(
        "SELECT * FROM `{$table}` WHERE `{$c['code']}` = ? AND `{$c['active']}` = 1 LIMIT 1",
        [$code]
    );
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function nu_decode_layout($form) {
    $c = nu_form_columns();
    $layout = [];
    if (!empty($form[$c['layout']])) $layout = json_decode($form[$c['layout']], true);
    return is_array($layout) ? $layout : [];
}

function nu_get_pk($table) {
    try {
        // Try MySQL syntax first
        $stmt = nu_q("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['Column_name'])) {
            return $row['Column_name'];
        }
    } catch (Throwable $e) {
        // Fallback or SQLite
    }

    try {
        // Try SQLite syntax
        $stmt = nu_q("PRAGMA table_info(`{$table}`)");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!empty($r['pk'])) {
                return $r['name'];
            }
        }
    } catch (Throwable $e) {}

    // Fallback to candidates commonly used
    $candidates = ['request_id', 'service_type_id', 'service_log_id', 'id'];
    try {
        $stmt = nu_db()->query("SELECT * FROM `{$table}` LIMIT 1");
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach ($candidates as $c) {
                    if (array_key_exists($c, $row)) {
                        return $c;
                    }
                }
            }
        }
    } catch (Throwable $e) {}

    return 'id';
}

function nu_is_pk_string_type($table, $pk) {
    try {
        $driver = nu_db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = nu_q("PRAGMA table_info(`{$table}`)");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (strtolower($r['name']) === strtolower($pk)) {
                    $type = strtolower($r['type']);
                    if (strpos($type, 'char') !== false || strpos($type, 'text') !== false || strpos($type, 'uuid') !== false || strpos($type, 'string') !== false) {
                        return true;
                    }
                }
            }
        } else {
            // MySQL / MariaDB
            $stmt = nu_q("DESCRIBE `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if (strtolower($r['Field']) === strtolower($pk)) {
                    $type = strtolower($r['Type']);
                    if (strpos($type, 'char') !== false || strpos($type, 'text') !== false || strpos($type, 'uuid') !== false || strpos($type, 'varchar') !== false) {
                        return true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Fallback or ignore
    }
    return false;
}

function nu_get_record($table, $id) {
    $table = nu_safe_ident($table);
    if (!$table || !$id) return [];
    $pk = nu_get_pk($table);
    $stmt = nu_q("SELECT * FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1", [$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function nu_fetch_sql_options($sql) {
    $stmt = nu_q($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $out[] = ['value' => $vals[0] ?? '', 'label' => $vals[1] ?? ($vals[0] ?? '')];
    }
    return $out;
}

function nu_field_name($field)  { return $field['name']  ?? ($field['fieldname']  ?? ''); }
function nu_field_label($field) { return $field['label'] ?? ($field['fieldlabel'] ?? nu_field_name($field)); }
function nu_field_type($field)  { return $field['type']  ?? ($field['fieldtype']  ?? 'text'); }

function nu_field_value($record, $field) {
    $name = nu_field_name($field);
    if ($name === '') return '';
    $type = nu_field_type($field);
    if ($type === 'lookup') {
        $dbCol = nu_resolve_lookup_store_col($field);
        if ($dbCol !== '' && array_key_exists($dbCol, $record)) return $record[$dbCol];
    }
    if (array_key_exists($name, $record)) return $record[$name];
    if (isset($field['default_value'])) return $field['default_value'];
    if (isset($field['defaultvalue'])) return $field['defaultvalue'];
    return '';
}

function nu_attr($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function nu_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function nu_generate_uuid() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function nu_form_pk_type($form) {
    $c = nu_form_columns();
    return strtolower(trim((string)($form[$c['pk_type']] ?? 'auto')));
}

function nu_render_options($field, $selectedValue = null) {
    $options   = [];
    $optSource = $field['options_source'] ?? ($field['source_type'] ?? 'manual');

    if ($optSource === 'table') {
        $tbl      = nu_safe_ident($field['options_table']     ?? '');
        $valCol   = nu_safe_ident($field['options_value_col'] ?? '');
        $labelCol = nu_safe_ident($field['options_label_col'] ?? $valCol);
        $filter   = trim((string)($field['options_filter']   ?? ''));
        if ($tbl !== '' && $valCol !== '') {
            $sql = "SELECT `{$valCol}`, `{$labelCol}` FROM `{$tbl}`";
            if ($filter !== '') $sql .= ' WHERE ' . $filter;
            try { $options = nu_fetch_sql_options($sql); } catch (Throwable $e) { $options = []; }
        }
    } elseif ($optSource === 'sql' && !empty($field['sql_source'])) {
        try { $options = nu_fetch_sql_options($field['sql_source']); } catch (Throwable $e) { $options = []; }
    } else {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    }

    $selectedArr = is_array($selectedValue)
        ? array_map('strval', $selectedValue)
        : array_map('trim', explode(',', (string)$selectedValue));

    $html = '';
    foreach ($options as $opt) {
        $value    = (string)($opt['value'] ?? '');
        $label    = (string)($opt['label'] ?? $value);
        $selected = in_array($value, $selectedArr, true) ? ' selected' : '';
        $html .= '<option value="' . nu_attr($value) . '"' . $selected . '>' . nu_html($label) . '</option>';
    }
    return $html;
}

function nu_resolve_lookup_id_col($lookup) {
    $idCol = nu_safe_ident($lookup['id_column'] ?? ($lookup['idcolumn'] ?? ''));
    if ($idCol !== '') return $idCol;
    $table = nu_safe_ident($lookup['table'] ?? '');
    if ($table !== '') return nu_get_pk($table);
    return 'id';
}

function nu_resolve_lookup_store_col($field) {
    $lookup = $field['lookup'] ?? [];
    $col = $lookup['store_field']
        ?? $lookup['storefield']
        ?? $lookup['store_col']
        ?? $lookup['storeCol']
        ?? $lookup['id_column']
        ?? $lookup['idcolumn']
        ?? '';
    $col = nu_safe_ident($col);
    if ($col !== '') return $col;
    $name = nu_safe_ident(nu_field_name($field));
    if ($name !== '' && !preg_match('/^lookup_\d+$/', $name)) return $name;
    return '';
}

function nu_resolve_lookup_display_col($lookup) {
    $col = $lookup['display_column']
        ?? $lookup['displaycolumn']
        ?? $lookup['display_col']
        ?? $lookup['displayCol']
        ?? '';
    return nu_safe_ident($col);
}

function nu_render_lookup_display($field, $value) {
    $lookup     = $field['lookup'] ?? [];
    $table      = nu_safe_ident($lookup['table'] ?? '');
    $idCol      = nu_resolve_lookup_id_col($lookup);
    $displayCol = nu_resolve_lookup_display_col($lookup);
    if ($table === '' || $idCol === '' || $displayCol === '' || $value === '' || $value === null) return (string)($value ?? '');
    try {
        $stmt = nu_q("SELECT `{$displayCol}` FROM `{$table}` WHERE `{$idCol}` = ? LIMIT 1", [$value]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) { return (string)$value; }
}

function nu_field_is_fk($field)           { return !empty($field['is_fk']); }
function nu_field_hide_in_grid($field)     { return !empty($field['hide_in_grid']); }
function nu_field_server_readonly($field)  { return !empty($field['server_readonly']); }

// ── helpers for field flags ────────────────────────────────────────────────
function nu_field_readonly($field)               { return !empty($field['readonly']); }
function nu_field_hidden($field)                 { return !empty($field['hidden']); }
function nu_field_hidden_for_normal_users($field) { return !empty($field['hidden_for_normal_users']); }
function nu_field_no_duplicate($field)           { return !empty($field['no_duplicate']); }

/**
 * Returns true if the current user is an admin/globeadmin.
 * Tries NuAuth first, then falls back to $_SESSION directly.
 */
function nu_current_user_is_admin() {
    static $result = null;
    if ($result !== null) return $result;
    try {
        // 1. Try NuAuth
        if (class_exists('NuAuth')) {
            $auth = NuAuth::getInstance();
            if ($auth->checkAuth()) {
                $user = $auth->getCurrentUser();
                if (is_array($user)) {
                    $role = strtolower((string)($user['usr_role'] ?? ($user['role'] ?? ($user['access_level'] ?? ''))));
                    $result = in_array($role, ['admin', 'globeadmin'], true);
                    return $result;
                }
            }
        }
        // 2. Fallback: check $_SESSION directly
        $sessionRole = strtolower((string)(
            $_SESSION['usr_role'] ?? $_SESSION['role'] ?? $_SESSION['access_level'] ?? ''
        ));
        $result = in_array($sessionRole, ['admin', 'globeadmin'], true);
    } catch (Throwable $e) {
        $result = false;
    }
    return $result;
}


function nu_customnumber_normalize_value($value, $field = []) {
    $v = (string)$value;
    $prefix = (string)($field['prefix'] ?? '');
    $suffix = (string)($field['suffix'] ?? '');
    $thousand = (string)($field['thousand_separator'] ?? ',');
    $decimal = (string)($field['decimal_separator'] ?? '.');

    if ($prefix !== '' && str_starts_with($v, $prefix)) {
        $v = substr($v, strlen($prefix));
    }
    if ($suffix !== '' && $suffix !== '' && substr($v, -strlen($suffix)) === $suffix) {
        $v = substr($v, 0, -strlen($suffix));
    }

    $v = trim($v);
    if ($thousand !== '') $v = str_replace($thousand, '', $v);
    if ($decimal !== '.' && $decimal !== '') $v = str_replace($decimal, '.', $v);
    $v = preg_replace('/[^0-9\.\-]/', '', $v);

    return $v;
}

function nu_customnumber_display_value($value, $field = []) {
    if ($value === null || $value === '') return '';
    $num = nu_customnumber_normalize_value($value, $field);
    if ($num === '' || !is_numeric($num)) return (string)$value;

    $decimals = isset($field['decimals']) ? (int)$field['decimals'] : 2;
    $thousand = (string)($field['thousand_separator'] ?? ',');
    $decimal = (string)($field['decimal_separator'] ?? '.');
    $prefix = (string)($field['prefix'] ?? '');
    $suffix = (string)($field['suffix'] ?? '');

    return $prefix . number_format((float)$num, $decimals, $decimal, $thousand) . $suffix;
}

function nu_generate_autonumber($field, $data, $formCode) {
    $db = nu_db();

    // 1. Ensure table exists
    static $tableChecked = false;
    if (!$tableChecked) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS nu_sequence_counters (
                seq_code VARCHAR(100) NOT NULL PRIMARY KEY,
                seq_value INT UNSIGNED NOT NULL DEFAULT 0
            )");
        } catch (Throwable $t) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS nu_sequence_counters (
                    seq_code VARCHAR(100) NOT NULL,
                    seq_value INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (seq_code)
                )");
            } catch (Throwable $t2) {
                nu_log("Failed to create autonumber sequence table: " . $t2->getMessage());
            }
        }
        $tableChecked = true;
    }

    // 2. Resolve Sequence Code
    $seqCode = trim($field['sequence_code'] ?? '');
    if ($seqCode === '') {
        $fieldName = nu_field_name($field);
        $seqCode = $formCode . '_' . $fieldName;
    }
    $seqCode = preg_replace('/[^a-zA-Z0-9_]/', '_', $seqCode);

    // Parse Prefix Map if present
    $prefixMap = [];
    if (!empty($field['prefix_map'])) {
        $lines = explode("\n", $field['prefix_map']);
        foreach ($lines as $line) {
            $parts = explode(":", $line, 2);
            if (count($parts) === 2) {
                $prefixMap[trim(strtolower($parts[0]))] = trim($parts[1]);
            }
        }
    }

    // Helper to evaluate pattern
    $evalPattern = function($pattern) use ($data, $prefixMap) {
        if ($pattern === '') return '';

        // Find placeholders like {field_name} or {{field_name}}
        return preg_replace_callback('/\{+([a-zA-Z0-9_]+)\}+/', function($matches) use ($data, $prefixMap) {
            $fieldName = $matches[1];
            $fieldNameLower = strtolower($fieldName);

            // Dynamic date/time placeholders support
            if ($fieldNameLower === 'year') {
                return date('Y');
            }
            if ($fieldNameLower === 'month') {
                return date('m');
            }
            if ($fieldNameLower === 'day') {
                return date('d');
            }

            $val = isset($data[$fieldName]) ? trim((string)$data[$fieldName]) : '';

            // Check in map
            $valLower = strtolower($val);
            if (isset($prefixMap[$valLower])) {
                return $prefixMap[$valLower];
            }
            return $val;
        }, $pattern);
    };

    // 3. Resolve Prefix & Suffix
    $prefix = $evalPattern($field['prefix_pattern'] ?? '');
    $suffix = $evalPattern($field['suffix_pattern'] ?? '');

    // 4. Atomic Increment and Fetch Counter
    $stmt = $db->prepare("SELECT seq_value FROM nu_sequence_counters WHERE seq_code = ?");
    $stmt->execute([$seqCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        try {
            $stmtIns = $db->prepare("INSERT INTO nu_sequence_counters (seq_code, seq_value) VALUES (?, 0)");
            $stmtIns->execute([$seqCode]);
        } catch (Throwable $e) {
            // ignore parallel insert errors
        }
    }

    $stmtUpd = $db->prepare("UPDATE nu_sequence_counters SET seq_value = seq_value + 1 WHERE seq_code = ?");
    $stmtUpd->execute([$seqCode]);

    $stmtSel = $db->prepare("SELECT seq_value FROM nu_sequence_counters WHERE seq_code = ?");
    $stmtSel->execute([$seqCode]);
    $counter = (int)$stmtSel->fetchColumn();
    if ($counter <= 0) $counter = 1;

    // 5. Padding
    $paddingLen = isset($field['padding_length']) ? (int)$field['padding_length'] : 6;
    if ($paddingLen < 0) $paddingLen = 0;
    $paddedCounter = str_pad((string)$counter, $paddingLen, '0', STR_PAD_LEFT);

    return $prefix . $paddedCounter . $suffix;
}

function nu_render_field($field, $value = '', $record = []) {
    $type  = nu_field_type($field);
     if ($type === 'signaturepad') {
        $type = 'picturecanvas';
    }
    $name  = nu_field_name($field);
    $label = nu_field_label($field);

    if ($name === '' && !in_array($type, ['html','content','button','fieldset'], true)) return '';

    // ── hidden_for_normal_users — skip rendering for non-admins ────────────
    if (nu_field_hidden_for_normal_users($field) && !nu_current_user_is_admin()) {
        if ($name !== '') {
            return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
        }
        return '';
    }

    // ── hidden flag — render as hidden input, show nothing ─────────────────
    if (nu_field_hidden($field)) {
        if ($name !== '') {
            return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
        }
        return '';
    }

    if (nu_field_is_fk($field)) {
        return '<input type="hidden"'
             . ' data-field="' . nu_attr($name) . '"'
             . ' name="'       . nu_attr($name) . '"'
             . ' data-is-fk="1"'
             . ' value="'      . nu_attr($value) . '">';
    }

    $required    = !empty($field['required']) ? ' required' : '';
    $phText      = $field['placeholder'] ?? '';
    $placeholder = $phText !== '' ? ' placeholder="' . nu_attr($phText) . '"' : '';
    $helpText    = $field['help_text'] ?? ($field['helptext'] ?? '');
    $cssClass    = trim('nu-input ' . ($field['css_class'] ?? ($field['cssclass'] ?? '')));

    $col = (int)($field['col'] ?? 12);
    if ($col < 1 || $col > 12) $col = 12;

    $helpHtml = $helpText !== ''
        ? '<div style="font-size:11px;color:#999;margin-top:3px;">' . nu_html($helpText) . '</div>'
        : '';

    // ── apply readonly flag to field rendering ──────────────────────────────
    $isReadonly   = nu_field_readonly($field);
    $readonlyAttr = $isReadonly ? ' readonly' : '';
    $readonlyStyle = $isReadonly ? 'background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;' : '';

    if ($type === 'uploadbutton') {
        global $nuConfig;
        $buttonText = trim((string)($field['button_text'] ?? 'Upload'));

        // Developer determined upload target & settings
        $uploadTarget = trim((string)($field['upload_target'] ?? 'default'));
        $onedriveClientId = trim((string)($field['onedrive_client_id'] ?? ''));

        // Dynamic Allowed Extensions Configuration
        $allowedExts = [];
        if (!empty($field['allowed_extensions'])) {
            $exts = explode(',', $field['allowed_extensions']);
            foreach ($exts as $e) {
                $e = trim($e);
                if ($e !== '') {
                    $allowedExts[] = str_starts_with($e, '.') ? $e : '.' . $e;
                }
            }
        } else {
            // Fallback to global config limits
            $allowedExts = array_map(function($ext) {
                return '.' . ltrim($ext, '.');
            }, $nuConfig['allowedFileTypes'] ?? []);
        }

        // Dynamic Max File Size Configuration
        if (!empty($field['max_file_size_mb'])) {
            $maxSize = (int)$field['max_file_size_mb'] * 1024 * 1024;
        } else {
            $maxSize = $nuConfig['maxUploadSize'] ?? 10 * 1024 * 1024;
        }

        $multiple = !empty($field['multiple']) ? ' multiple' : '';
        $maxFiles = !empty($multiple) ? (isset($field['max_files']) ? (int)$field['max_files'] : null) : 1;
        if ($maxFiles !== null && $maxFiles <= 0) {
            $maxFiles = null;
        }
        $preview = !empty($field['preview']);
        $currentValue = is_string($value) ? $value : '';

        $html = '<div class="nu-field-wrap nu-field-uploadbutton" id="wrap_' . nu_attr($name) . '">';
        $html .= '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        $html .= '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" id="btn_' . nu_attr($name) . '" style="margin:0;">' . nu_html($buttonText) . '</button>';
        $html .= '<input type="text" class="' . nu_attr($cssClass) . '" name="' . nu_attr($name) . '" id="' . nu_attr($name) . '" value="' . nu_attr($currentValue) . '"' . $placeholder . ' readonly>';
        if ($currentValue !== '') {
            $html .= '<button type="button" class="nu-btn nu-btn-danger nu-btn-sm" id="clear_' . nu_attr($name) . '" style="padding:4px 8px;">Clear</button>';
        }
        $html .= '</div>';

        if ($preview && $currentValue !== '') {
            $html .= '<div class="nu-upload-preview" style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">';
            $filesArray = array_map('trim', explode(',', $currentValue));
            foreach ($filesArray as $fName) {
                if ($fName !== '') {
                    if (str_starts_with($fName, 'http://') || str_starts_with($fName, 'https://')) {
                        // OneDrive/external cloud url preview
                        $parsedUrl = parse_url($fName, PHP_URL_PATH) ?? '';
                        $ext = strtolower(pathinfo($parsedUrl, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true)) {
                            $html .= '<div style="display:inline-block;text-align:center;">';
                            $html .= '<img src="' . nu_attr($fName) . '" style="max-height:80px;border-radius:4px;border:1px solid var(--border-color);cursor:pointer;" onclick="window._showImageLightbox(' . nu_attr(json_encode($fName)) . ')">';
                            $html .= '<div style="font-size:10px;color:var(--text-tertiary);margin-top:2px;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Cloud Image</div>';
                            $html .= '</div>';
                        } else {
                            $html .= '<div style="margin-top:4px;font-size:11px;color:var(--text-tertiary);">OneDrive: <a href="' . nu_attr($fName) . '" target="_blank" style="color:var(--color-primary);text-decoration:underline;">View Link</a></div>';
                        }
                    } else {
                        // Local upload preview
                        $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true)) {
                            $html .= '<div style="display:inline-block;text-align:center;">';
                            $html .= '<img src="api/file.php?name=' . rawurlencode($fName) . '" style="max-height:80px;border-radius:4px;border:1px solid var(--border-color);cursor:pointer;" onclick="window._showImageLightbox(' . nu_attr(json_encode('uploads/' . $fName)) . ')">';
                            $html .= '<div style="font-size:10px;color:var(--text-tertiary);margin-top:2px;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . nu_html(basename($fName)) . '</div>';
                            $html .= '</div>';
                        } else {
                            $html .= '<div style="margin-top:4px;font-size:11px;color:var(--text-tertiary);">Current: <a href="#" data-nu-view-name="' . nu_attr($fName) . '" style="color:var(--color-primary);text-decoration:underline;">' . nu_html(basename($fName)) . '</a></div>';
                        }
                    }
                }
            }
            $html .= '</div>';
        }

        $html .= $helpHtml;
        $html .= '</div>';

        $html .= '<script>
        (function(){
          var btn = document.getElementById("btn_' . $name . '");
          var t = document.getElementById("' . $name . '");
          var clearBtn = document.getElementById("clear_' . $name . '");
          if (!btn || !t || btn.dataset.uppyBound) return;
          btn.dataset.uppyBound = "1";

          if (clearBtn) {
            clearBtn.addEventListener("click", function() {
              t.value = "";
              var prv = document.querySelector("#wrap_' . $name . ' .nu-upload-preview");
              if (prv) prv.remove();
              clearBtn.remove();
            });
          }

          function launchOneDrivePicker() {
            const clientId = ' . json_encode($onedriveClientId) . ';
            if (!clientId) {
              NuApp.toast("Error: OneDrive Client ID is not configured in properties panel!", "error");
              return;
            }
            const isMultiple = ' . json_encode(!empty($multiple)) . ';
            const odOptions = {
              clientId: clientId,
              action: "share",
              multiSelect: isMultiple,
              advanced: {},
              success: function(response) {
                if (response && response.value && response.value.length > 0) {
                  const links = response.value.map(item => item.permissions[0].link.webUrl);
                  if (t.value) {
                    t.value = t.value + ", " + links.join(", ");
                  } else {
                    t.value = links.join(", ");
                  }
                  NuApp.toast("OneDrive link acquired!", "success");

                  let notice = document.getElementById("notice_' . $name . '");
                  if (!notice) {
                    notice = document.createElement("div");
                    notice.id = "notice_' . $name . '";
                    notice.style.cssText = "margin-top:6px;font-size:11px;color:var(--warning,#f59e0b);font-weight:600;";
                    notice.textContent = "⚠️ Save form to apply changes and view new file previews.";
                    document.getElementById("wrap_' . $name . '").appendChild(notice);
                  }
                }
              },
              cancel: function() {
                NuApp.toast("OneDrive selection canceled.", "info");
              },
              error: function(err) {
                NuApp.toast("OneDrive Error: " + (err.message || String(err)), "error");
                console.error("OneDrive error", err);
              }
            };
            OneDrive.open(odOptions);
          }

          btn.addEventListener("click", function(){
            const targetMode = ' . json_encode($uploadTarget) . ';
            if (targetMode === "onedrive") {
              if (typeof OneDrive === "undefined") {
                NuApp.toast("OneDrive Picker SDK is loading...", "info");
                const script = document.createElement("script");
                script.src = "https://js.live.net/v7.2/OneDrive.js";
                script.onload = launchOneDrivePicker;
                document.head.appendChild(script);
              } else {
                launchOneDrivePicker();
              }
              return;
            }

            // Local Server upload mode using Uppy
            let modalId = "uppy_modal_' . $name . '";
            let modalContainer = document.getElementById(modalId);
            if (!modalContainer) {
              modalContainer = document.createElement("div");
              modalContainer.id = modalId;
              document.body.appendChild(modalContainer);
            }

            const allowedTypes = ' . json_encode($allowedExts) . ';
            const maxFileSize = ' . json_encode($maxSize) . ';
            const maxNumFiles = ' . json_encode($maxFiles) . ';

            const uppy = new Uppy.Uppy({
              autoProceed: false,
              restrictions: {
                maxFileSize: maxFileSize,
                allowedFileTypes: allowedTypes,
                maxNumberOfFiles: maxNumFiles
              }
            })
            .use(Uppy.Dashboard, {
              target: modalContainer,
              inline: false,
              trigger: "#btn_' . $name . '",
              closeModalOnClickOutside: true,
              showProgressDetails: true,
              proudlyDisplayPoweredByUppy: false,
              metaFields: []
            })
            .use(Uppy.XHRUpload, {
              endpoint: "api/upload.php" + (targetMode === "local_only" ? "?target=local_only" : ""),
              fieldName: "file",
              formData: true,
              headers: { "X-CSRF-Token": window.nuCsrfToken || "" },
              getResponseError: function (text, xhr) { try { var j = JSON.parse(text); if (j && j.error) return new Error(j.error); } catch (e) {} return new Error(xhr && xhr.status === 413 ? "File is too large for the server" : xhr && xhr.status === 403 ? "Session expired or invalid security token - reload the page" : "Upload failed (HTTP " + (xhr ? xhr.status : "?") + ")"); },
              validateStatus: function (status, text) { if (status < 200 || status >= 300) return false; try { var j = JSON.parse(text); return !(j && j.success === false); } catch (e) { return false; } },
            });

            uppy.getPlugin("Dashboard").openModal();

            uppy.on("complete", (result) => {
              if (result.successful.length > 0) {
                const names = result.successful.map(file => file.response.body.name);
                if (t.value) {
                  t.value = t.value + ", " + names.join(", ");
                } else {
                  t.value = names.join(", ");
                }
                const warns = result.successful.map(function(f){ return f.response.body.warning; }).filter(Boolean);
                if (warns.length) { NuApp.toast("Saved on this server, but the cloud copy failed: " + warns[0], "error"); } else { NuApp.toast("Upload successful!", "success"); }
                uppy.getPlugin("Dashboard").closeModal();
                uppy.close();
                modalContainer.remove();

                let notice = document.getElementById("notice_' . $name . '");
                if (!notice) {
                  notice = document.createElement("div");
                  notice.id = "notice_' . $name . '";
                  notice.style.cssText = "margin-top:6px;font-size:11px;color:var(--warning,#f59e0b);font-weight:600;";
                  notice.textContent = "⚠️ Save form to apply changes and view new file previews.";
                  document.getElementById("wrap_' . $name . '").appendChild(notice);
                }
              }
            });

            uppy.on("dashboard:modal-closed", () => {
              uppy.close();
              modalContainer.remove();
            });
          });
        })();
        </script>';

        return $html;
    }

    if ($type === 'autonumber') {
        $displayValue = $value !== null ? (string)$value : '';
        if ($displayValue === '') {
            $displayValue = '[Auto-Generated]';
        }

        $html = '<div class="nu-field-wrap nu-field-autonumber">';
        $html .= '<input type="text" class="' . nu_attr($cssClass) . '" ' .
                 'name="' . nu_attr($name) . '" ' .
                 'id="' . nu_attr($name) . '" ' .
                 'value="' . nu_attr($displayValue) . '"' .
                 ' style="background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;"' .
                 ' readonly>';
        $html .= $helpHtml;
        $html .= '</div>';

        return $html;
    }

    if ($type === 'signaturepad' || $type === 'picturecanvas') {
        $canvasWidth = max(100, (int)($field['canvas_width'] ?? 400));
        $canvasHeight = max(80, (int)($field['canvas_height'] ?? 220));
        $bg = (string)($field['background_color'] ?? '#ffffff');
        $pen = (string)($field['pen_color'] ?? '#000000');
        $penWidth = max(1, (int)($field['pen_width'] ?? 2));
        $clearButton = !empty($field['clear_button']);
        $canvasId = $name . '__canvas';
        $hiddenId = $name . '__hidden';

        $html = '<div class="nu-field-wrap nu-field-picturecanvas">';
        $html .= '<input type="hidden" name="' . nu_attr($name) . '" id="' . nu_attr($hiddenId) . '" value="' . nu_attr((string)$value) . '"' . $required . '>';
        $html .= '<div style="border:1px solid #d9dee7;border-radius:8px;padding:8px;background:#fff;display:inline-block;max-width:100%;">';
        $html .= '<canvas id="' . nu_attr($canvasId) . '" width="' . $canvasWidth . '" height="' . $canvasHeight . '" style="display:block;border:1px solid #cfd6df;border-radius:6px;background:' . nu_attr($bg) . ';max-width:100%;touch-action:none;"></canvas>';
        if ($clearButton) {
            $html .= '<div style="margin-top:8px;"><button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" id="' . nu_attr($canvasId . '__clear') . '">Clear</button></div>';
        }
        $html .= '</div>';
        $html .= $helpHtml;
        $html .= '</div>';

        $html .= '<script>
        (function(){
          var canvas = document.getElementById(' . json_encode($canvasId) . ');
          var hidden = document.getElementById(' . json_encode($hiddenId) . ');
          if(!canvas || !hidden || canvas.dataset.nbBound) return;
          canvas.dataset.nbBound = "1";

          var ctx = canvas.getContext("2d");
          var drawing = false;
          var rect = null;

          function resetBg(){
            ctx.fillStyle = ' . json_encode($bg) . ';
            ctx.fillRect(0,0,canvas.width,canvas.height);
          }

          function pos(e){
            rect = rect || canvas.getBoundingClientRect();
            var p = e.touches ? e.touches[0] : e;
            return {
              x: (p.clientX - rect.left) * (canvas.width / rect.width),
              y: (p.clientY - rect.top) * (canvas.height / rect.height)
            };
          }

          function save(){
            hidden.value = canvas.toDataURL("image/png");
          }

          function start(e){
            drawing = true;
            rect = canvas.getBoundingClientRect();
            var p = pos(e);
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            e.preventDefault();
          }

          function move(e){
            if(!drawing) return;
            var p = pos(e);
            ctx.lineWidth = ' . (int)$penWidth . ';
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            ctx.strokeStyle = ' . json_encode($pen) . ';
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            e.preventDefault();
          }

          function end(){
            if(!drawing) return;
            drawing = false;
            save();
          }

          resetBg();

          if(hidden.value){
            var img = new Image();
            img.onload = function(){
              ctx.clearRect(0,0,canvas.width,canvas.height);
              resetBg();
              ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            };
            img.src = hidden.value;
          }

          canvas.addEventListener("mousedown", start);
          canvas.addEventListener("mousemove", move);
          window.addEventListener("mouseup", end);

          canvas.addEventListener("touchstart", start, {passive:false});
          canvas.addEventListener("touchmove", move, {passive:false});
          canvas.addEventListener("touchend", end, {passive:false});

          var clearBtn = document.getElementById(' . json_encode($canvasId . '__clear') . ');
          if(clearBtn){
            clearBtn.addEventListener("click", function(){
              ctx.clearRect(0,0,canvas.width,canvas.height);
              resetBg();
              hidden.value = "";
            });
          }
        })();
        </script>';

        return $html;
    }

    if ($type === 'customnumber') {
        $displayValue = nu_customnumber_display_value($value, $field);
        $decimals = isset($field['decimals']) ? (int)$field['decimals'] : 2;
        $thousand = (string)($field['thousand_separator'] ?? ',');
        $decimal = (string)($field['decimal_separator'] ?? '.');
        $prefix = (string)($field['prefix'] ?? '');
        $suffix = (string)($field['suffix'] ?? '');
        $allowNegative = !empty($field['allow_negative']) ? '1' : '0';

        $html = '<div class="nu-field-wrap nu-field-customnumber">';
        $html .= '<input type="text" class="' . nu_attr($cssClass) . '" ' .
                 'name="' . nu_attr($name) . '" ' .
                 'id="' . nu_attr($name) . '" ' .
                 'value="' . nu_attr($displayValue) . '"' .
                 $placeholder . $required .
                 ' data-decimals="' . nu_attr((string)$decimals) . '"' .
                 ' data-thousand="' . nu_attr($thousand) . '"' .
                 ' data-decimal="' . nu_attr($decimal) . '"' .
                 ' data-prefix="' . nu_attr($prefix) . '"' .
                 ' data-suffix="' . nu_attr($suffix) . '"' .
                 ' data-allow-negative="' . nu_attr($allowNegative) . '">';
        $html .= $helpHtml;
        $html .= '</div>';

        $html .= '<script>
        (function(){
          var el = document.getElementById(' . json_encode($name) . ');
          if(!el || el.dataset.nbBound) return;
          el.dataset.nbBound = "1";

          function normalize(v){
            var prefix = el.dataset.prefix || "";
            var suffix = el.dataset.suffix || "";
            var thousand = el.dataset.thousand || ",";
            var decimal = el.dataset.decimal || ".";
            if(prefix && v.indexOf(prefix) === 0) v = v.slice(prefix.length);
            if(suffix && v.slice(-suffix.length) === suffix) v = v.slice(0, -suffix.length);
            if(thousand) v = v.split(thousand).join("");
            if(decimal && decimal !== ".") v = v.split(decimal).join(".");
            v = v.replace(/[^0-9.\-]/g, "");
            return v;
          }

          function format(v){
            if(v === "" || isNaN(v)) return "";
            var decimals = parseInt(el.dataset.decimals || "2", 10);
            var thousand = el.dataset.thousand || ",";
            var decimal = el.dataset.decimal || ".";
            var prefix = el.dataset.prefix || "";
            var suffix = el.dataset.suffix || "";
            var n = Number(v);
            var parts = n.toFixed(decimals).split(".");
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
            return prefix + parts.join(decimal) + suffix;
          }

          el.addEventListener("blur", function(){
            var raw = normalize(el.value);
            if(raw === "") return;
            el.value = format(raw);
          });

          var form = el.closest("form");
          if(form && !form.dataset.nbCustomNumberBound){
            form.dataset.nbCustomNumberBound = "1";
            form.addEventListener("submit", function(){
              var nums = form.querySelectorAll(".nu-field-customnumber input[data-decimals]");
              nums.forEach(function(inp){
                inp.value = normalize(inp.value);
              });
            });
          }
        })();
        </script>';

        return $html;
    }

    $fullWidthTypes = ['subform', 'html', 'content', 'button', 'fieldset', 'checkbox'];

    if (in_array($type, $fullWidthTypes, true)) {

        if ($type === 'checkbox') {
            // readonly checkbox: disable interaction
            $checkedAttr  = !empty($value) ? ' checked' : '';
            $disabledAttr = $isReadonly ? ' disabled' : '';
            $control = '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '"'
                     . ' style="grid-column:span ' . $col . ';min-width:0;padding:6px 0;">'
                     . '<label style="display:inline-flex;align-items:center;gap:8px;cursor:' . ($isReadonly ? 'default' : 'pointer') . ';">'
                     . '<input type="hidden" name="' . nu_attr($name) . '" value="0">'
                     . '<input type="checkbox" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="1"' . $checkedAttr . $disabledAttr . ' style="width:15px;height:15px;">'
                     . '<span style="font-weight:600;font-size:13px;">' . nu_html($label) . '</span>'
                     . '</label>'
                     . $helpHtml
                     . '</div>';
            return $control;
        }

        if ($type === 'subform') {
            $sf       = $field['subform'] ?? [];
            $sfCode   = nu_safe_ident($sf['form_code'] ?? ($sf['formcode'] ?? ($field['sf_form_code'] ?? ($field['form_code'] ?? $name))));
            $sfFk     = nu_safe_ident($sf['fk_field']  ?? ($sf['fkfield']  ?? ($field['sf_fk_field']  ?? ($field['fk_field']  ?? ''))));
            $sfViewRaw = $sf['view'] ?? ($sf['subform_view'] ?? ($field['subform_view'] ?? 'grid'));
            $sfView   = in_array($sfViewRaw, ['grid','form','inline'], true) ? $sfViewRaw : 'grid';
            $sfParent = (string)($field['_parent_id'] ?? '');
            $parentFormCode = (string)($field['_parent_form_code'] ?? '');

            $sfSearchable   = (!isset($sf['searchable']) && !isset($field['searchable'])) || !empty($sf['searchable']) || !empty($field['searchable']) ? '1' : '0';
            $sfSelectedOnly = !empty($sf['selected_fields_only']) || !empty($field['selected_fields_only']) ? '1' : '0';
            $sfViewRoles    = trim((string)($sf['view_roles'] ?? ($field['view_roles'] ?? '')));
            $sfEditRoles    = trim((string)($sf['edit_roles'] ?? ($field['edit_roles'] ?? '')));

            return '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;margin-bottom:8px;">'
                 . '<div class="nu-subform-container"'
                 . ' data-subform-code="'  . nu_attr($sfCode)   . '"'
                 . ' data-subform-fk="'    . nu_attr($sfFk)     . '"'
                 . ' data-subform-view="'  . nu_attr($sfView)   . '"'
                 . ' data-parent-id="'     . nu_attr($sfParent) . '"'
                 . ' data-parent-form-code="' . nu_attr($parentFormCode) . '"'
                 . ' data-subform-searchable="' . $sfSearchable . '"'
                 . ' data-subform-selected-fields-only="' . $sfSelectedOnly . '"'
                 . ' data-subform-view-roles="' . nu_attr($sfViewRoles) . '"'
                 . ' data-subform-edit-roles="' . nu_attr($sfEditRoles) . '"'
                 . ' data-subform-label="' . nu_attr($label) . '"'
                 . ' style="border:1px solid #ddd;border-radius:8px;overflow:hidden;">'
                 . '<div class="nu-subform-toolbar" style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--bg-elevated,#f8f9fa);border-bottom:1px solid #ddd;">'
                 . '<span style="font-weight:600;font-size:13px;">' . nu_html($label) . '</span>'
                 . '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuSubform.addRow(this)">+ Add Row</button>'
                 . '</div>'
                 . '<div class="nu-subform-body" style="padding:0;">'
                 . '<div class="nu-subform-loading" style="padding:20px;text-align:center;color:#888;font-size:13px;">Loading...</div>'
                 . '</div>'
                 . '</div>'
                 . '</div>';
        }

        if ($type === 'html' || $type === 'content') {
            $htmlContent = $field['html_content'] ?? ($field['default_value'] ?? '');
            return '<div class="nu-field-wrapper"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;">'
                 . '<div>' . $htmlContent . '</div>'
                 . '</div>';
        }

        if ($type === 'button') {
            $action  = $field['button_action'] ?? '';
            $onclick = $action !== '' ? ' onclick="' . nu_attr($action) . '"' : '';
            return '<div class="nu-field-wrapper"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;padding:4px 0;">'
                 . '<button type="button" class="nu-btn nu-btn-primary"' . $onclick . '>' . nu_html($label) . '</button>'
                 . '</div>';
        }

        if ($type === 'fieldset') {
            $legend = $field['legend'] ?? $label;
            return '<fieldset style="grid-column:span ' . $col . ';margin-bottom:16px;padding:12px;border:1px solid #ddd;border-radius:8px;">'
                 . '<legend>' . nu_html($legend) . '</legend>'
                 . '</fieldset>';
        }
    }


    if ($type === 'hidden') {
        return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
    }
    
    

    // ── uuid — always build $control first, then fall through to wrapper ────
    if ($type === 'uuid') {
        if ($value !== '' && $value !== null) {
            $control = '<input type="text" class="' . nu_attr($cssClass) . '" value="' . nu_attr($value) . '" readonly style="background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;width:100%;">'
                     . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
        } else {
            // New record — emit hidden only; fall through to wrapper so label is shown
            $control = '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="">';
        }
    } else {
        switch ($type) {

            case 'textarea':
                $rows    = (int)($field['rows'] ?? 3);
                $control = '<textarea class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $placeholder . $required . $readonlyAttr . ' rows="' . $rows . '" style="width:100%;resize:vertical;' . $readonlyStyle . '">'
                         . nu_html($value) . '</textarea>';
                break;

            case 'select2':
                $isMultiple  = !empty($field['multiple']);
                $allowClear  = isset($field['allow_clear']) ? (bool)$field['allow_clear'] : true;
                $selectMode  = $isMultiple ? 'multiple' : 'single';
                $multipleAttr = $isMultiple ? ' multiple' : '';
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $control  = '<select'
                          . ' class="' . nu_attr(trim($cssClass . ' nu-select2')) . '"'
                          . ' data-field="'       . nu_attr($name)         . '"'
                          . ' name="'             . nu_attr($name)         . '"'
                          . $required
                          . $multipleAttr
                          . $disabledAttr
                          . ' data-select-type="select2"'
                          . ' data-select-mode="' . $selectMode            . '"'
                          . ' data-allow-clear="' . ($allowClear ? 'true' : 'false') . '"'
                          . ' style="width:100%;">'.
                          '<option value="">Select...</option>'
                          . nu_render_options($field, $value)
                          . '</select>';
                break;

            case 'select':
                $isMultiple  = !empty($field['multiple']);
                $useSelect2  = !empty($field['select2']);
                $allowClear  = isset($field['allow_clear']) ? (bool)$field['allow_clear'] : true;
                $selectMode  = $isMultiple ? 'multiple' : 'single';
                $multipleAttr = $isMultiple ? ' multiple' : '';
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $s2Class  = $useSelect2 ? ' nu-select2' : '';
                $s2Attrs  = $useSelect2
                    ? ' data-select-type="select2"'
                    . ' data-select-mode="' . $selectMode . '"'
                    . ' data-allow-clear="' . ($allowClear ? 'true' : 'false') . '"'
                    : '';
                $control  = '<select'
                          . ' class="' . nu_attr(trim($cssClass . $s2Class)) . '"'
                          . ' data-field="' . nu_attr($name) . '"'
                          . ' name="'       . nu_attr($name) . '"'
                          . $required
                          . $multipleAttr
                          . $disabledAttr
                          . $s2Attrs
                          . ' style="width:100%;">'
                          . '<option value="">Select...</option>'
                          . nu_render_options($field, $value)
                          . '</select>';
                break;

            case 'radio':
            case 'checkbox_group':
                $options = [];
                $optSource  = $field['options_source'] ?? ($field['source_type'] ?? 'manual');
                if ($optSource === 'table') {
                    $tbl      = nu_safe_ident($field['options_table']     ?? '');
                    $valCol   = nu_safe_ident($field['options_value_col'] ?? '');
                    $labelCol = nu_safe_ident($field['options_label_col'] ?? $valCol);
                    $filter   = trim((string)($field['options_filter']   ?? ''));
                    if ($tbl !== '' && $valCol !== '') {
                        $sql = "SELECT `{$valCol}`, `{$labelCol}` FROM `{$tbl}`";
                        if ($filter !== '') $sql .= ' WHERE ' . $filter;
                        try { $options = nu_fetch_sql_options($sql); } catch (Throwable $e) { $options = []; }
                    }
                } elseif ($optSource === 'sql' && !empty($field['sql_source'])) {
                    try { $options = nu_fetch_sql_options($field['sql_source']); } catch (Throwable $e) { $options = []; }
                } else {
                    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                }
                $inputType   = ($type === 'radio') ? 'radio' : 'checkbox';
                $currentVals = is_array($value) ? array_map('strval', $value) : array_map('trim', explode(',', (string)$value));
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $control = '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;padding-top:4px;">';
                foreach ($options as $opt) {
                    $optValue = (string)($opt['value'] ?? '');
                    $optLabel = (string)($opt['label'] ?? $optValue);
                    $checked  = in_array($optValue, $currentVals, true) ? ' checked' : '';
                    $iname    = ($type === 'radio') ? $name : $name . '[]';
                    $control .= '<label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;cursor:' . ($isReadonly ? 'default' : 'pointer') . ';">'
                              . '<input type="' . $inputType . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($iname) . '" value="' . nu_attr($optValue) . '"' . $checked . $disabledAttr . '>'
                              . nu_html($optLabel) . '</label>';
                }
                $control .= '</div>';
                break;

            case 'date':
                $control = '<input type="date" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'time':
                $control = '<input type="time" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'datetime':
                $v       = $value ? str_replace(' ', 'T', substr((string)$value, 0, 16)) : '';
                $control = '<input type="datetime-local" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($v) . '"' . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'number':
                $min  = isset($field['min'])  && $field['min']  !== '' ? ' min="'  . nu_attr($field['min'])  . '"' : '';
                $max  = isset($field['max'])  && $field['max']  !== '' ? ' max="'  . nu_attr($field['max'])  . '"' : '';
                $step = isset($field['step']) && $field['step'] !== '' ? ' step="' . nu_attr($field['step']) . '"' : '';
                $control = '<input type="number" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $min . $max . $step . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'email':
                $control = '<input type="email" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'password':
                $control = '<input type="password" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '"' . $placeholder . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'file':
            case 'image':
                $accept   = !empty($field['accept'])          ? ' accept="' . nu_attr($field['accept']) . '"' : ($type === 'image' ? ' accept="image/*"' : '');
                $multiple = !empty($field['multiple_upload']) ? ' multiple' : '';
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $control  = '<input type="file" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $accept . $multiple . $disabledAttr . ' style="width:100%;">';
                if (!empty($value)) $control .= '<div style="margin-top:4px;font-size:11px;color:#888;">Current: ' . nu_html($value) . '</div>';
                break;

            case 'lookup':
                $lookup      = $field['lookup'] ?? [];
                $lTable      = $lookup['table'] ?? '';
                $lIdCol      = nu_resolve_lookup_id_col($lookup);
                $lDisplayCol = nu_resolve_lookup_display_col($lookup);
                $lFilter     = $lookup['filter'] ?? '';
                $lExtra      = $lookup['extra']  ?? '';
                $lAdditional = $lookup['additional_fields'] ?? '';
                $displayVal  = nu_render_lookup_display($field, $value);
                if ($isReadonly) {
                    $control = '<div style="display:flex;gap:6px;align-items:center;">'
                        . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">'
                        . '<input type="text" class="' . nu_attr($cssClass) . '" value="' . nu_attr($displayVal) . '" readonly style="flex:1;background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;">'
                        . '</div>';
                } else {
                    $control = '<div style="display:flex;gap:6px;align-items:center;">'
                        . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">'
                        . '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '_display" value="' . nu_attr($displayVal) . '" style="flex:1;"'
                        . ' data-lookup-name="' . nu_attr($name) . '"'
                        . ' data-lookup-table="' . nu_attr($lTable) . '"'
                        . ' data-lookup-id="' . nu_attr($lIdCol) . '"'
                        . ' data-lookup-display="' . nu_attr($lDisplayCol) . '"'
                        . ' data-lookup-filter="' . nu_attr($lFilter) . '"'
                        . ' data-lookup-extra="' . nu_attr($lExtra) . '"'
                        . ' data-lookup-additional-fields="' . nu_attr($lAdditional) . '"'
                        . ' data-last-val="' . nu_attr($displayVal) . '"'
                        . '>'
                        . '<button type="button" class="nu-btn nu-btn-ghost" style="white-space:nowrap;" onclick="openLookupModal('
                        . '\'' . addslashes($name) . '\',\'' . addslashes($lTable) . '\',\'' . addslashes($lIdCol) . '\',\'' . addslashes($lDisplayCol) . '\',\'' . addslashes($lFilter) . '\',\'' . addslashes($lExtra) . '\',\'' . addslashes($lAdditional) . '\''
                        . ')">&#x1F50D;</button>'
                        . '<button type="button" class="nu-btn nu-btn-ghost" onclick="clearLookup(\'' . addslashes($name) . '\')">&#x2715;</button>'
                        . '</div>';
                }
                break;

            case 'calculated':
                $expr    = $field['formula'] ?? ($field['calculated'] ?? ($field['calc_formula'] ?? ''));
                $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" data-calculated="true" data-expression="' . nu_attr($expr) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '" readonly style="width:100%;background:var(--bg-offset,#f5f5f5);color:#888;">';
                break;

            default:
                $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;
        }
    }

    $reqStar   = !empty($field['required']) ? ' <span class="nu-required-asterisk" style="color:#ef4444;font-weight:bold;" title="Required field">*</span>' : '';
    $labelHtml = '<label for="nuf_' . nu_attr($name) . '"'
               . ' style="display:block;font-weight:600;font-size:13px;color:#555;'
               . 'text-align:right;padding-right:10px;padding-top:7px;'
               . 'min-width:160px;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
               . nu_html($label) . $reqStar
               . '</label>';

    $wrapInner = '<div style="display:flex;align-items:flex-start;gap:0;width:100%;">'
               . $labelHtml
               . '<div style="flex:1;min-width:0;">'
               . $control
               . $helpHtml
               . '</div>'
               . '</div>';

    $rowAlign = strtolower((string)($field['row_align'] ?? ($field['align'] ?? 'left')));
    $gridColCss = "grid-column: span {$col};";
    if (!empty($field['grid_column_style'])) {
        $gridColCss = $field['grid_column_style'];
    } elseif ($rowAlign === 'right') {
        $startCol = 12 - $col + 1;
        $gridColCss = "grid-column: {$startCol} / span {$col}; margin-left: auto;";
    } elseif ($rowAlign === 'center') {
        $startCol = (int)floor((12 - $col) / 2) + 1;
        $gridColCss = "grid-column: {$startCol} / span {$col}; margin-left: auto; margin-right: auto;";
    }

    return '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '"'
         . ' style="' . $gridColCss . 'min-width:0;margin-bottom:6px;">'
         . $wrapInner
         . '</div>';
}

function nu_section_color($index) {
    $palette = [
        ['border'=>'#01696f','bg'=>'rgba(1,105,111,0.04)','head'=>'rgba(1,105,111,0.08)','text'=>'#01696f'],
        ['border'=>'#7a39bb','bg'=>'rgba(122,57,187,0.04)','head'=>'rgba(122,57,187,0.08)','text'=>'#7a39bb'],
        ['border'=>'#d19900','bg'=>'rgba(209,153,0,0.04)', 'head'=>'rgba(209,153,0,0.08)', 'text'=>'#9a7000'],
        ['border'=>'#437a22','bg'=>'rgba(67,122,34,0.04)', 'head'=>'rgba(67,122,34,0.08)', 'text'=>'#437a22'],
        ['border'=>'#a13544','bg'=>'rgba(161,53,68,0.04)', 'head'=>'rgba(161,53,68,0.08)', 'text'=>'#a13544'],
    ];
    return $palette[$index % count($palette)];
}

function nu_toggle_script() {
    return '<script id="nuToggleInit">'
         . 'if(!window.nuToggleContainer){'
         . 'window.nuToggleContainer=function(btn){'
         . 'if(!btn)return;'
         . 'var tid=btn.getAttribute("data-target");'
         . 'if(!tid)return;'
         . 'var body=document.getElementById(tid);'
         . 'if(!body)return;'
         . 'var hidden=body.style.display==="none"||body.style.display==="";'
         . 'body.style.display=hidden?"block":"none";'
         . 'if(btn.classList.contains("nu-group-toggle")){'
         . 'btn.innerHTML=hidden?"\u2212":"+";'
         . '}else{'
         . 'btn.innerHTML=hidden?"&#9660;":"&#9654;";'
         . '}'
         . '}}'
         . '</s' . 'cript>';
}

function nu_render_group_container($node, $record) {
    static $grpCounter = 0;
    $grpCounter++;

    $id         = 'grp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $node['id'] ?? ('g_' . $grpCounter . '_' . date("U") . '_' . rand(100,999)));
    $label      = nu_html($node['label'] ?? 'Group');
    $collapsible= !empty($node['collapsible']);
    $collapsed  = !empty($node['collapsed']);
    $bodyStyle  = $collapsed ? 'display:none;' : 'display:block;';

    // Outer styling
    $bStyle = !empty($node['border_style']) ? $node['border_style'] : 'solid';
    $bWidth = !empty($node['border_width']) ? $node['border_width'] : '1px';
    $bColor = !empty($node['border_color']) ? $node['border_color'] : '#ddd';

    $outerStyle = 'border-radius:8px;margin-bottom:16px;overflow:hidden;';
    if (!empty($node['border_color']) || !empty($node['border_style']) || !empty($node['border_width'])) {
        $outerStyle .= 'border:' . $bWidth . ' ' . $bStyle . ' ' . $bColor . ';';
    } else {
        $outerStyle .= 'border:1px solid #ddd;';
    }

    if (!empty($node['bg_color'])) {
        $outerStyle .= 'background-color:' . $node['bg_color'] . ';';
    }

    // Header styling
    $headerStyle = 'display:flex;align-items:center;justify-content:space-between;gap:6px;padding:8px 14px;user-select:none;';
    if (!empty($node['border_color']) || !empty($node['border_style']) || !empty($node['border_width'])) {
        $headerStyle .= 'border-bottom:' . $bWidth . ' ' . $bStyle . ' ' . $bColor . ';';
    } else {
        $headerStyle .= 'border-bottom:1px solid #ddd;';
    }

    if (!empty($node['text_color'])) {
        $headerStyle .= 'color:' . $node['text_color'] . ';';
    }

    if (!empty($node['header_bg_color'])) {
        $headerStyle .= 'background:' . $node['header_bg_color'] . ';';
    } else if (!empty($node['bg_color'])) {
        $headerStyle .= 'background:rgba(0,0,0,0.03);';
    } else {
        $headerStyle .= 'background:var(--bg-elevated,#f8f9fa);';
    }

    $html  = '<div class="nu-group" id="' . nu_attr($id) . '" style="' . $outerStyle . '">';
    $html .= '<div class="nu-group-header" style="' . $headerStyle . ($collapsible ? 'cursor:pointer;' : '') . '"'
               . ($collapsible ? ' onclick="nuToggleContainer(this.querySelector(\'.nu-group-toggle\'))"' : '') . '>';

    $html .= '<span style="font-weight:600;font-size:13.5px;color:inherit;">' . $label . '</span>';

    if ($collapsible) {
        $toggleStyle = 'background:none;border:1px solid currentColor;border-radius:50%;cursor:pointer;font-size:12px;width:18px;height:18px;display:flex;align-items:center;justify-content:center;padding:0;line-height:1;flex-shrink:0;transition:all 0.2s;font-weight:bold;';
        if (!empty($node['text_color'])) {
            $toggleStyle .= 'color:' . $node['text_color'] . ';';
        } else {
            $toggleStyle .= 'color:#666;';
        }
        $html .= '<button type="button" class="nu-group-toggle"'
               . ' data-target="' . nu_attr($id) . '-body"'
               . ' onclick="event.stopPropagation();nuToggleContainer(this)"'
               . ' style="' . $toggleStyle . '">'
               . ($collapsed ? '+' : '&#8722;') . '</button>'; // &#8722; is HTML minus sign
    }
    $html .= '</div>';
    $html .= '<div id="' . nu_attr($id) . '-body" class="nu-group-body" style="padding:12px 10px;' . $bodyStyle . '">';

    if (isset($node['children'])) {
        $gi = 0;
        foreach ($node['children'] as $child) {
            $html .= nu_render_layout_node($child, $record, $gi++);
        }
    }

    // Render rows if present (from canvas-level Group container)
    $ROW_STYLE = 'display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;';
    if (isset($node['rows'])) {
        foreach ($node['rows'] as $row) {
            $fields = $row['fields'] ?? [];
            if (empty($fields)) continue;
            $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
            foreach ($fields as $field) {
                $html .= nu_render_field($field, nu_field_value($record, $field), $record);
            }
            $html .= '</div>';
        }
    }

    $html .= '</div></div>';
    return $html;
}

function nu_render_layout_node($node, $record, $sectionIndex = 0) {
    $type = $node['type'] ?? 'field';
    
    if ($type === 'tab') {
        return nu_render_tab_container($node, $record);
    }


    if ($type === 'section') {
        $id         = 'sec_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $node['id'] ?? ('s' . $sectionIndex));
        $label      = nu_html($node['label'] ?? 'Section');
        $collapsible= !empty($node['collapsible']);
        $collapsed  = !empty($node['collapsed']);
        $col        = nu_section_color($sectionIndex);
        $bodyStyle  = $collapsed ? 'display:none;' : 'display:block;';
        $icon       = $collapsed ? '&#9654;' : '&#9660;';

        $html  = '<div class="nu-section" id="' . nu_attr($id) . '" style="'
               . 'border:1.5px solid ' . $col['border'] . ';'
               . 'border-radius:10px;margin-bottom:20px;'
               . 'background:' . $col['bg'] . ';overflow:hidden;">';
        $html .= '<div class="nu-section-header" style="'
               . 'display:flex;align-items:center;gap:6px;'
               . 'padding:10px 16px;'
               . 'background:' . $col['head'] . ';'
               . 'border-bottom:1px solid ' . $col['border'] . ';'
               . 'user-select:none;'
               . ($collapsible ? 'cursor:pointer;' : '') . '"'
               . ($collapsible ? ' onclick="nuToggleContainer(this.querySelector(\'.nu-section-toggle\'))"' : '') . '>';
        if ($collapsible) {
            $html .= '<button type="button" class="nu-section-toggle"'
                   . ' data-target="' . nu_attr($id) . '-body"'
                   . ' onclick="event.stopPropagation();nuToggleContainer(this)"'
                   . ' style="background:none;border:none;cursor:pointer;font-size:14px;'
                   . 'color:' . $col['text'] . ';padding:0 6px 0 0;line-height:1;flex-shrink:0;">'
                   . $icon . '</button>';
        }
        $html .= '<span style="font-weight:700;font-size:14px;color:' . $col['text'] . ';">' . $label . '</span>';
        $html .= '</div>';
        $html .= '<div id="' . nu_attr($id) . '-body" class="nu-section-body" style="padding:16px 12px;' . $bodyStyle . '">';
        $si = 0;
        foreach (($node['children'] ?? []) as $child) {
            $html .= nu_render_layout_node($child, $record, $si++);
        }
        $html .= '</div></div>';
        return $html;
    }

    if ($type === 'group') {
        return nu_render_group_container($node, $record);
    }

    if ($type === 'row') {
        $html = '<div class="nu-form-row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;">';
        foreach (($node['children'] ?? []) as $field) {
            $html .= nu_render_field($field, nu_field_value($record, $field), $record);
        }
        $html .= '</div>';
        return $html;
    }

    $col = (int)($node['col'] ?? 12);
    $node['col'] = $col;
    return '<div class="nu-form-row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;">'
         . nu_render_field($node, nu_field_value($record, $node), $node)
         . '</div>';
}

function nu_render_tab_container(array $item, array $record) {
    $tabs = $item['tabs'] ?? [];
    if (empty($tabs)) return '';

    static $tcIdx = 0;
    $tcIdx++;
    $containerId = 'nu-tabs-tc' . $tcIdx;

    $html  = '<div class="nu-tabs" id="' . nu_attr($containerId) . '" style="margin-bottom:16px;">';
    $html .= '<div class="nu-tab-bar" role="tablist" style="display:flex;flex-wrap:wrap;gap:0;border-bottom:2px solid var(--color-primary,#4f6bed);padding:0 4px;">';

    foreach ($tabs as $i => $tab) {
        $tabLabel = $tab['name'] ?? ($tab['label'] ?? ('Tab ' . ($i + 1)));
        $tabId    = 'tab_' . $tcIdx . '_' . $i;
        $active   = $i === 0;
        $html .= '<button type="button"'
               . ' class="nu-tab-btn' . ($active ? ' nu-tab-active' : '') . '"'
               . ' role="tab"'
               . ' data-tab="' . nu_attr($tabId) . '"'
               . ' data-tabs-container="' . nu_attr($containerId) . '"'
               . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
               . ' onclick="nuTabSwitch(this)"'
               . ' style="padding:6px 16px;font-size:13px;border:1px solid transparent;'
               . 'border-radius:6px 6px 0 0;margin-bottom:-2px;cursor:pointer;background:' . ($active ? '#fff' : 'none') . ';'
               . 'border-color:' . ($active ? 'var(--color-primary,#4f6bed) var(--color-primary,#4f6bed) #fff' : 'transparent') . ';'
               . 'font-weight:' . ($active ? '600' : '400') . ';'
               . 'color:' . ($active ? 'var(--color-primary,#4f6bed)' : '#555') . ';"'
               . '>' . nu_html($tabLabel) . '</button>';
    }
    $html .= '</div>';

    $ROW_STYLE = 'display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;';

    foreach ($tabs as $i => $tab) {
        $tabId  = 'tab_' . $tcIdx . '_' . $i;
        $hidden = $i !== 0;

        $html .= '<div'
               . ' id="' . nu_attr($containerId) . '-panel-' . nu_attr($tabId) . '"'
               . ' class="nu-tab-panel"'
               . ' role="tabpanel"'
               . ' data-tab="' . nu_attr($tabId) . '"'
               . ' style="padding:12px 4px;' . ($hidden ? 'display:none;' : '') . '"'
               . '>';

        $rows = $tab['rows'] ?? [];
        foreach ($rows as $rowIdx => $row) {
            // A row can be a plain row { fields: [...] } or a group { type: "group", rows: [...] }
            $rowType = $row['type'] ?? 'row';

            if ($rowType === 'group') {
                $html .= nu_render_group_container($row, $record);
            } else {
                // Plain row with fields
                $fields = $row['fields'] ?? [];
                if (empty($fields)) continue;
                $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
                foreach ($fields as $field) {
                    $html .= nu_render_field($field, nu_field_value($record, $field), $record);
                }
                $html .= '</div>';
            }
        }

        $html .= '</div>';
    }

    $html .= '</div>';

    // Tab switch script (only once per page)
    static $tabScriptEmitted = false;
    if (!$tabScriptEmitted) {
        $tabScriptEmitted = true;
        $html .= '<script>if(!window.nuTabSwitch){window.nuTabSwitch=function(btn){'
               . 'var cid=btn.getAttribute("data-tabs-container");'
               . 'var tid=btn.getAttribute("data-tab");'
               . 'var wrap=document.getElementById(cid);'
               . 'if(!wrap)return;'
               . 'wrap.querySelectorAll(".nu-tab-btn").forEach(function(b){'
               . 'var on=b.getAttribute("data-tab")===tid;'
               . 'b.classList.toggle("nu-tab-active",on);'
               . 'b.setAttribute("aria-selected",on?"true":"false");'
               . 'b.style.background=on?"#fff":"none";'
               . 'b.style.borderColor=on?"var(--color-primary,#4f6bed) var(--color-primary,#4f6bed) #fff":"transparent";'
               . 'b.style.fontWeight=on?"600":"400";'
               . 'b.style.color=on?"var(--color-primary,#4f6bed)":"#555";'
               . '});'
               . 'wrap.querySelectorAll(".nu-tab-panel").forEach(function(p){'
               . 'var on=p.getAttribute("data-tab")===tid;'
               . 'p.style.display=on?"":"none";'
               . '});'
               . '}}</script>';
    }

    return $html;
}

function nu_render_form_html($form, $record = [], $recordId = null) {
    $c        = nu_form_columns();
    $layout   = nu_decode_layout($form);
    $formCode = $form[$c['code']]  ?? '';
    $formTable= $form[$c['table']] ?? '';

    $layout = nu_inject_parent_context($layout, $formTable, (string)($recordId ?? ''), $formCode);

    $customPhp = trim((string)($form[$c['custom_php']] ?? ''));
    if ($customPhp !== '') {
        try {
            $data = $record;
            eval($customPhp);
            if (is_array($data)) $record = array_merge($record, $data);
        } catch (Throwable $e) {
            nu_log('custom_php error in form ' . $formCode . ': ' . $e->getMessage(), 'render');
            $layout[] = ['type' => 'html', 'html_content' => '<!-- custom_php error: ' . htmlspecialchars($e->getMessage()) . ' -->', 'col' => 12];
        }
    }

    $formStyle = nu_parse_form_style($layout);
    $formStyleAttrs = nu_build_form_style_css($formStyle);

    $html  = '<form ' . $formStyleAttrs
           . ' data-form-code="'  . nu_attr($formCode)   . '"'
           . ' data-table="'      . nu_attr($formTable)  . '"'
           . ' data-record-id="'  . nu_attr((string)$recordId) . '"'
           . ' data-is-new="'     . ($recordId ? '0' : '1') . '"'
           . ' onsubmit="event.preventDefault(); submitNuForm(this);">';

    $customCss = trim((string)($form[$c['custom_css']] ?? ''));
    if ($customCss !== '') {
        $scopeId = 'nu-form-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $formCode);
        $html .= '<style id="' . nu_attr($scopeId) . '-styles">'
              . '.nu-generated-form[data-form-code="' . addslashes($formCode) . '"] {'
              . '/* custom css wrapper */'
              . '}'
              . $customCss
              . '</style>';
    }

    $structuredNodes = [];
    $flatByRow       = [];
    $flatNoRow       = [];

    foreach ($layout as $node) {
        $type = $node['type'] ?? 'field';
        if (in_array($type, ['section', 'group', 'row', 'tab'], true)) {
            if ($flatNoRow || $flatByRow) {
                $structuredNodes[] = ['_flat_flush' => true, 'byRow' => $flatByRow, 'noRow' => $flatNoRow];
                $flatByRow = [];
                $flatNoRow = [];
            }
            $structuredNodes[] = ['_structured' => true, 'node' => $node];
        } else {
            $ri = $node['row_index'] ?? null;
            if ($ri !== null) {
                $flatByRow[(string)$ri][] = $node;
            } else {
                $flatNoRow[] = $node;
            }
        }
    }
    if ($flatNoRow || $flatByRow) {
        $structuredNodes[] = ['_flat_flush' => true, 'byRow' => $flatByRow, 'noRow' => $flatNoRow];
    }

    $ROW_STYLE = 'display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;';

    $si = 0;
    foreach ($structuredNodes as $entry) {
        if (!empty($entry['_structured'])) {
            $html .= nu_render_layout_node($entry['node'], $record, $si++);
        } elseif (!empty($entry['_flat_flush'])) {
            ksort($entry['byRow']);
            foreach ($entry['byRow'] as $rowFields) {
                $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
                foreach ($rowFields as $node) {
                    $html .= nu_render_field($node, nu_field_value($record, $node), $node);
                }
                $html .= '</div>';
            }
            foreach ($entry['noRow'] as $node) {
                $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
                $html .= nu_render_field($node, nu_field_value($record, $node), $node);
                $html .= '</div>';
            }
        }
    }

    $html .= '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;padding-top:12px;border-top:1px solid #eee;">';
    $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="closeNuForm(this)">Cancel</button>';
    $html .= '<button type="submit" class="nu-btn nu-btn-primary">Save</button>';
    $html .= '</div></form>';

    $customJs       = trim((string)($form[$c['custom_js']] ?? ''));
    $jsBeforeSave   = trim((string)($form[$c['js_before_save']] ?? ''));
    $jsAfterSave    = trim((string)($form[$c['js_after_save']] ?? ''));

    if ($customJs !== '' || $jsBeforeSave !== '' || $jsAfterSave !== '') {
        $escapedCode = htmlspecialchars($formCode, ENT_QUOTES, 'UTF-8');
        $html .= '<script data-nu-form-hooks="' . $escapedCode . '">';
        $html .= '(function(){';
        $html .= 'var _fc=' . json_encode($formCode) . ';';

        if ($customJs !== '') {
            $html .= 'window._nuFormOnLoad=window._nuFormOnLoad||{};';
            $html .= 'window._nuFormOnLoad[_fc]=function(formEl){';
            $html .= 'var nu=window.nu||{};';
            $html .= $customJs;
            $html .= '};';
        }

        if ($jsBeforeSave !== '') {
            $html .= 'window._nuFormBeforeSave=window._nuFormBeforeSave||{};';
            $html .= 'window._nuFormBeforeSave[_fc]=function(formEl,data){';
            $html .= 'var nu=window.nu||{};';
            $html .= $jsBeforeSave;
            $html .= '};';
        }

        if ($jsAfterSave !== '') {
            $html .= 'window._nuFormAfterSave=window._nuFormAfterSave||{};';
            $html .= 'window._nuFormAfterSave[_fc]=function(formEl,result){';
            $html .= 'var nu=window.nu||{};';
            $html .= $jsAfterSave;
            $html .= '};';
        }

        if ($customJs !== '') {
            $html .= 'var _el=document.querySelector(\'form.nu-generated-form[data-form-code="\'+_fc+\'"]\');';
            $html .= 'if(_el && typeof window._nuFormOnLoad[_fc]==="function") window._nuFormOnLoad[_fc](_el);';
        }

        $html .= '})();';
        $html .= '</script>';
    }

    return $html;
}

function nu_inject_parent_context(array $layout, string $parentTable, string $parentId, string $parentFormCode): array {
    $walk = function(&$items) use (&$walk, $parentTable, $parentId, $parentFormCode) {
        if (!is_array($items)) return;
        foreach ($items as &$item) {
            if (!is_array($item)) continue;

            $type = strtolower(trim((string)($item['type'] ?? '')));
            if ($type === 'subform') {
                $item['_parent_table']     = $parentTable;
                $item['_parent_id']        = $parentId;
                $item['_parent_form_code'] = $parentFormCode;
            }

            // Recurse into all possible nested structures
            if (isset($item['children']) && is_array($item['children'])) {
                $walk($item['children']);
            }
            if (isset($item['fields']) && is_array($item['fields'])) {
                $walk($item['fields']);
            }
            if (isset($item['rows']) && is_array($item['rows'])) {
                $walk($item['rows']);
            }
            if (isset($item['tabs']) && is_array($item['tabs'])) {
                $walk($item['tabs']);
            }
        }
        unset($item);
    };

    $walk($layout);
    return $layout;
}

function nu_flatten_fields(array $layout): array {

    return nu_flatten_layout_fields($layout);
}


function nu_flatten_layout_for_grid($layout) {
    return array_values(array_filter(
        nu_flatten_fields($layout),
        function ($f) {
            $type = nu_field_type($f);
            if (nu_field_hide_in_grid($f)) return false;
            if (in_array($type, ['uploadbutton', 'signaturepad', 'picturecanvas'], true)) return false;
            return true;
        }
    ));
}

function nu_field_type_to_sql($fieldType) {
    switch ($fieldType) {
        case 'number':     return 'DOUBLE NULL DEFAULT NULL';
        case 'date':       return 'DATE NULL DEFAULT NULL';
        case 'time':       return 'TIME NULL DEFAULT NULL';
        case 'datetime':   return 'DATETIME NULL DEFAULT NULL';
        case 'checkbox':   return 'TINYINT(1) NOT NULL DEFAULT 0';
        case 'textarea':   return 'TEXT NULL DEFAULT NULL';
        case 'uuid':       return 'VARCHAR(36) NULL DEFAULT NULL';
        default:           return 'VARCHAR(255) NULL DEFAULT NULL';
    }
}

function nu_create_form_table($tableName, $pkType, array $fields) {
    $tableName = nu_safe_ident($tableName);
    if ($tableName === '') {
        nu_log('create_form_table called with empty table name', 'DDL');
        return ['created' => false, 'error' => 'Empty table name'];
    }

    if (nu_table_exists($tableName)) {
        nu_log('Table already exists, skipping CREATE: ' . $tableName, 'DDL');
        return ['created' => false, 'error' => null];
    }

    $pkTypeLower = strtolower(trim($pkType));
    if ($pkTypeLower === 'uuid') {
        $pkDef = '`id` VARCHAR(36) NOT NULL PRIMARY KEY';
    } else {
        $pkDef = '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    $colDefs = [$pkDef];
    $added   = ['id' => true];

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        $type = nu_field_type($field);
        if ($name === '' || isset($added[$name])) continue;
        if (in_array($type, ['html','content','button','fieldset','subform','heading','divider'], true)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_safe_ident(nu_resolve_lookup_store_col($field));
            if ($dbCol === '' || isset($added[$dbCol])) continue;
            $name = $dbCol;
        }

        $colDefs[] = '`' . $name . '` ' . nu_field_type_to_sql($type);
        $added[$name] = true;
    }

    $driver = strtolower(nu_db()->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $sql = 'CREATE TABLE `' . $tableName . '` (' . implode(', ', $colDefs) . ')';
    } else {
        $sql = 'CREATE TABLE `' . $tableName . '` (' . implode(', ', $colDefs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }
    nu_log('Creating table: ' . $tableName . ' | SQL: ' . $sql, 'DDL');

    try {
        nu_db()->exec($sql);
        nu_log('Table created successfully: ' . $tableName, 'DDL');
        return ['created' => true, 'error' => null];
    } catch (Throwable $e) {
        nu_log('CREATE TABLE failed for ' . $tableName . ': ' . $e->getMessage(), 'DDL');
        return ['created' => false, 'error' => $e->getMessage()];
    }
}

function nu_sync_form_table_columns($tableName, array $fields) {
    $tableName = nu_safe_ident($tableName);
    if ($tableName === '' || !nu_table_exists($tableName)) {
        nu_log('sync_columns skipped — table missing or empty name: ' . $tableName, 'DDL');
        return ['added' => [], 'errors' => []];
    }

    $existing = nu_get_table_columns($tableName);
    $added    = [];
    $errors   = [];

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        $type = nu_field_type($field);
        if ($name === '') continue;
        if (in_array($type, ['html','content','button','fieldset','subform','heading','divider'], true)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_safe_ident(nu_resolve_lookup_store_col($field));
            if ($dbCol === '') continue;
            $name = $dbCol;
        }

        if (isset($existing[$name])) continue;

        $colSql = nu_field_type_to_sql($type);
        $alterSql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$name}` {$colSql}";
        nu_log('Adding column: ' . $tableName . '.' . $name . ' | SQL: ' . $alterSql, 'DDL');

        try {
            nu_db()->exec($alterSql);
            $added[]         = $name;
            $existing[$name] = true;
            nu_log('Column added: ' . $tableName . '.' . $name, 'DDL');
        } catch (Throwable $e) {
            $errMsg = 'Column ' . $name . ': ' . $e->getMessage();
            $errors[] = $errMsg;
            nu_log('ALTER TABLE failed — ' . $errMsg, 'DDL');
        }
    }

    return ['added' => $added, 'errors' => $errors];
}

/* ── nuHash equivalent — session context for browse_php scripts ─── */

/**
 * Returns an associative array of current-user context, mirroring the old
 * nuHash() function. Keys match the old constants for easy migration.
 *
 * Available keys:
 *   USER_ID          — numeric or string user PK
 *   USERNAME         — login name
 *   USER_NAME        — display name
 *   ACCESS_LEVEL_CODE — role string (e.g. 'admin', 'SC', 'BSO')
 *   station          — usr_station column if it exists
 *   usr_*            — every column from the user row
 */
function nu_get_hash() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $hash = [
        'USER_ID'           => '',
        'USERNAME'          => '',
        'USER_NAME'         => '',
        'ACCESS_LEVEL_CODE' => '',
        'station'           => '',
    ];

    try {
        if (!class_exists('NuAuth')) { $cached = $hash; return $hash; }
        $auth = NuAuth::getInstance();
        if (!$auth->checkAuth()) { $cached = $hash; return $hash; }
        $user = $auth->getCurrentUser();
        if (!is_array($user)) { $cached = $hash; return $hash; }

        // Merge every column from the user row into the hash
        foreach ($user as $k => $v) {
            $hash[$k] = $v;
        }

        // Normalised shortcuts
        $hash['USER_ID']           = $user['usr_id']       ?? ($user['zzzzsys_user_id'] ?? ($user['id'] ?? ''));
        $hash['USERNAME']          = $user['usr_username'] ?? ($user['username'] ?? '');
        $hash['USER_NAME']         = $user['usr_name']     ?? ($user['name']     ?? '');
        $hash['ACCESS_LEVEL_CODE'] = $user['usr_role']     ?? ($user['role']     ?? ($user['access_level'] ?? ''));
        $hash['station']           = $user['usr_station']  ?? ($user['station']  ?? '');

    } catch (Throwable $e) {
        nu_log('nu_get_hash error: ' . $e->getMessage(), 'hash');
    }

    $cached = $hash;
    return $hash;
}

/* ── Subform handlers ─────────────────────────────────────────────── */

function nu_flatten_subforms(array $layout): array {
    $out = [];
    $walk = function($items) use (&$walk, &$out) {
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $type = strtolower(trim((string)($item['type'] ?? '')));
            if ($type === 'subform') {
                $out[] = $item;
                continue;
            }
            if ($type === 'tab') {
                foreach (($item['tabs'] ?? []) as $tab) {
                    if (!is_array($tab)) continue;
                    foreach (($tab['rows'] ?? []) as $row) {
                        $rowType = strtolower(trim((string)($row['type'] ?? 'row')));
                        if ($rowType === 'group') {
                            $walk($row['rows'] ?? []);
                        } else {
                            $walk($row['fields'] ?? []);
                        }
                    }
                }
                continue;
            }
            if ($type === 'section' || $type === 'group') {
                $walk($item['children'] ?? []);
                foreach (($item['rows'] ?? []) as $row) {
                    $walk($row['fields'] ?? []);
                }
                continue;
            }
            if ($type === 'row' || isset($item['fields'])) {
                $walk($item['fields'] ?? []);
                continue;
            }
        }
    };
    $walk($layout);
    return $out;
}

function nu_get_subform_roles($parentFormCode, $childFormCode, $fkField) {
    $defaults = ['view' => '', 'edit' => '', 'selected_fields_only' => false, 'searchable' => true];
    if ($parentFormCode === '') {
        return $defaults;
    }
    $parentForm = nu_get_form($parentFormCode);
    if (!$parentForm) {
        $table = nu_form_table_name();
        $c = nu_form_columns();
        try {
            $stmt = nu_q("SELECT * FROM `{$table}` WHERE LOWER(`{$c['code']}`) = LOWER(?) LIMIT 1", [$parentFormCode]);
            $parentForm = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    if (!$parentForm) {
        return $defaults;
    }
    $layout = nu_decode_layout($parentForm);
    $flat = nu_flatten_subforms($layout);
    foreach ($flat as $node) {
        $sf = $node['subform'] ?? [];
        $sfCode = nu_safe_ident($sf['form_code'] ?? ($sf['formcode'] ?? ($node['sf_form_code'] ?? ($node['form_code'] ?? ($node['name'] ?? '')))));
        $sfFk = nu_safe_ident($sf['fk_field'] ?? ($sf['fkfield'] ?? ($node['sf_fk_field'] ?? ($node['fk_field'] ?? ''))));
        if (strtolower($sfCode) === strtolower($childFormCode) && strtolower($sfFk) === strtolower($fkField)) {
            return [
                'view' => trim((string)($sf['view_roles'] ?? ($node['view_roles'] ?? ''))),
                'edit' => trim((string)($sf['edit_roles'] ?? ($node['edit_roles'] ?? ''))),
                'selected_fields_only' => !empty($sf['selected_fields_only']) || !empty($node['selected_fields_only']),
                'searchable' => (!isset($sf['searchable']) && !isset($node['searchable'])) || !empty($sf['searchable']) || !empty($node['searchable'])
            ];
        }
    }
    return $defaults;
}

function nu_handle_subform_fields() {
    $code = $_GET['code'] ?? '';
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $layout = nu_decode_layout($form);
    $pk     = $table !== '' ? nu_get_pk($table) : 'id';

    $allFields  = nu_flatten_layout($layout);
    $gridFields = nu_flatten_layout_for_grid($layout);

    // Decorate fields with formatters & conditional formatting rules from child form's browse layout
    $bLayoutJson = $form[$c['browse_layout']] ?? '';
    $bCols = [];
    if (is_string($bLayoutJson) && $bLayoutJson !== '') {
        $bCols = json_decode($bLayoutJson, true) ?: [];
    }
    $bMap = [];
    foreach ($bCols as $bCol) {
        $fn = strtolower($bCol['fieldname'] ?? '');
        if ($fn !== '') {
            $bMap[$fn] = $bCol;
        }
    }

    $decorateField = function(&$field) use ($bMap) {
        $fn = strtolower(nu_field_name($field));
        if (isset($bMap[$fn])) {
            $bCol = $bMap[$fn];
            foreach (['formatter', 'rules', 'cond_op', 'cond_val', 'cond_fg', 'cond_bg', 'btn_label', 'btn_class', 'btn_js', 'btn_has_lookup', 'btn_table', 'btn_id', 'btn_disp', 'btn_where'] as $prop) {
                if (isset($bCol[$prop])) {
                    $field[$prop] = $bCol[$prop];
                }
            }
        }
        $type = nu_field_type($field);
        if ($type === 'select' || $type === 'select2') {
            $options = [];
            $optSource = $field['options_source'] ?? ($field['source_type'] ?? 'manual');
            if ($optSource === 'table') {
                $tbl      = nu_safe_ident($field['options_table']     ?? '');
                $valCol   = nu_safe_ident($field['options_value_col'] ?? '');
                $labelCol = nu_safe_ident($field['options_label_col'] ?? $valCol);
                $filter   = trim((string)($field['options_filter']   ?? ''));
                if ($tbl !== '' && $valCol !== '') {
                    $sql = "SELECT `{$valCol}`, `{$labelCol}` FROM `{$tbl}`";
                    if ($filter !== '') $sql .= ' WHERE ' . $filter;
                    try { $options = nu_fetch_sql_options($sql); } catch (Throwable $e) { $options = []; }
                }
            } elseif ($optSource === 'sql' && !empty($field['sql_source'])) {
                try { $options = nu_fetch_sql_options($field['sql_source']); } catch (Throwable $e) { $options = []; }
            } else {
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            }
            $field['options'] = $options;
        }
    };

    foreach ($allFields as &$f) { $decorateField($f); } unset($f);
    foreach ($gridFields as &$f) { $decorateField($f); } unset($f);

    nu_json(['success' => true, 'data' => [
        'layout'     => $gridFields,
        'all_fields' => $allFields,
        'pk'         => $pk,
    ]]);
}

function nu_handle_subform_list() {
    $code           = $_GET['code']            ?? '';
    $fk             = nu_safe_ident($_GET['fk'] ?? '');
    $parentId       = $_GET['parent_id']       ?? '';
    $parentFormCode = $_GET['parent_form_code'] ?? '';

    nu_log("nu_handle_subform_list called with code='{$code}', fk='{$fk}', parent_id='{$parentId}', parent_form_code='{$parentFormCode}'", "subform_list");

    if ($code === '' || $fk === '' || $parentId === '') {
        nu_log("Error: Missing subform params", "subform_list");
        nu_json(['success' => false, 'error' => 'Missing subform params'], 400);
    }

    $form = nu_get_form($code);
    if (!$form) {
        nu_log("Error: Child form not found for code='{$code}'", "subform_list");
        nu_json(['success' => false, 'error' => 'Child form not found'], 404);
    }

    // ── Fetch Roles and Configs Pure Server-Side ──
    $sfMeta = nu_get_subform_roles($parentFormCode, $code, $fk);
    nu_log("sfMeta view roles: '" . ($sfMeta['view'] ?? '') . "', edit roles: '" . ($sfMeta['edit'] ?? '') . "', searchable: " . (($sfMeta['searchable'] ?? true) ? 'true' : 'false'), "subform_list");

    // ── Enforce View Roles ──
    $viewRoles = $sfMeta['view'];
    $auth = class_exists('NuAuth') ? NuAuth::getInstance() : null;
    $currentRole = $auth ? $auth->getCurrentRole() : '';
    if ($currentRole === '') {
        $currentRole = $_SESSION['usr_role'] ?? $_SESSION['role'] ?? $_SESSION['access_level'] ?? '';
    }
    if ($viewRoles !== '') {
        $allowed = array_map('trim', explode(',', strtolower($viewRoles)));
        $currentRoleLower = strtolower($currentRole);
        if (!in_array('*', $allowed, true) && !in_array('admin', $allowed, true) && !in_array('globeadmin', $allowed, true) && !in_array($currentRoleLower, $allowed, true)) {
            nu_json(['success' => false, 'error' => 'Access Denied: View Roles restriction.'], 403);
        }
    }

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $layout = nu_decode_layout($form);

    $allFields  = nu_flatten_layout($layout);
    $selectedFieldsOnly = $sfMeta['selected_fields_only'];

    $isAdmin = nu_current_user_is_admin();
    $gridFields = array_values(array_filter($allFields, function($f) use ($fk, $isAdmin) {
        $type = nu_field_type($f);
        if (in_array($type, ['html','heading','divider','fieldset','subform','button','uploadbutton','signaturepad','picturecanvas'], true)) return false;

        $fname = strtolower(nu_field_name($f));
        if ($fname === strtolower($fk)) return false;
        if (nu_field_hide_in_grid($f)) return false;
        if (nu_field_hidden($f)) return false;
        if (nu_field_hidden_for_normal_users($f) && !$isAdmin) return false;
        return true;
    }));

    // Decorate fields with formatters & conditional formatting rules from child form's browse layout
    $bLayoutJson = $form[$c['browse_layout']] ?? '';
    $bCols = [];
    if (is_string($bLayoutJson) && $bLayoutJson !== '') {
        $bCols = json_decode($bLayoutJson, true) ?: [];
    }
    $bMap = [];
    foreach ($bCols as $bCol) {
        $fn = strtolower($bCol['fieldname'] ?? '');
        if ($fn !== '') {
            $bMap[$fn] = $bCol;
        }
    }

    $decorateField = function(&$field) use ($bMap) {
        $fn = strtolower(nu_field_name($field));
        if (isset($bMap[$fn])) {
            $bCol = $bMap[$fn];
            foreach (['formatter', 'rules', 'cond_op', 'cond_val', 'cond_fg', 'cond_bg', 'btn_label', 'btn_class', 'btn_js', 'btn_has_lookup', 'btn_table', 'btn_id', 'btn_disp', 'btn_where'] as $prop) {
                if (isset($bCol[$prop])) {
                    $field[$prop] = $bCol[$prop];
                }
            }
        }
        $type = nu_field_type($field);
        if ($type === 'select' || $type === 'select2') {
            $options = [];
            $optSource = $field['options_source'] ?? ($field['source_type'] ?? 'manual');
            if ($optSource === 'table') {
                $tbl      = nu_safe_ident($field['options_table']     ?? '');
                $valCol   = nu_safe_ident($field['options_value_col'] ?? '');
                $labelCol = nu_safe_ident($field['options_label_col'] ?? $valCol);
                $filter   = trim((string)($field['options_filter']   ?? ''));
                if ($tbl !== '' && $valCol !== '') {
                    $sql = "SELECT `{$valCol}`, `{$labelCol}` FROM `{$tbl}`";
                    if ($filter !== '') $sql .= ' WHERE ' . $filter;
                    try { $options = nu_fetch_sql_options($sql); } catch (Throwable $e) { $options = []; }
                }
            } elseif ($optSource === 'sql' && !empty($field['sql_source'])) {
                try { $options = nu_fetch_sql_options($field['sql_source']); } catch (Throwable $e) { $options = []; }
            } else {
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            }
            $field['options'] = $options;
        }
    };

    foreach ($allFields as &$f) { $decorateField($f); } unset($f);
    foreach ($gridFields as &$f) { $decorateField($f); } unset($f);

    if ($table === '') {
        nu_log("Error: No table for child form '{$code}'", "subform_list");
        nu_json(['success' => false, 'error' => 'No table for child form'], 400);
    }

    $pk = nu_get_pk($table);
    nu_log("Table resolved: '{$table}', PK: '{$pk}'", "subform_list");

    // Get searchable fields
    $searchFields = [];
    foreach ($allFields as $field) {
        $fname = nu_safe_ident(nu_field_name($field));
        $searchable = !isset($field['searchable']) || $field['searchable'] === true || $field['searchable'] === 'true' || $field['searchable'] === 1 || $field['searchable'] === '1';
        if ($fname !== '' && $searchable) {
            $searchFields[] = $fname;
        }
    }

    $q = trim($_GET['q'] ?? '');
    $where = ["`{$fk}` = ?"];
    $params = [$parentId];

    if ($q !== '' && $sfMeta['searchable']) {
        $likes = [];
        foreach ($searchFields as $fn) {
            $likes[] = "`{$fn}` LIKE ?";
            $params[] = '%' . $q . '%';
        }
        if (!empty($likes)) {
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }
    }

    $whereSql = implode(' AND ', $where);
    $totalSql = "SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}";
    nu_log("Count query: {$totalSql} with params: " . json_encode($params), "subform_list");

    try {
        $total = (int)nu_q($totalSql, $params)->fetchColumn();
    } catch (Throwable $e) {
        nu_log("Database error in total count query: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), "subform_list");
        throw $e;
    }
    nu_log("Total records in subform table: {$total}", "subform_list");

    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = max(1, (int)($_GET['page_size'] ?? 10));
    $pages = max(1, (int)ceil($total / $pageSize));
    if ($page > $pages) $page = $pages;
    $offset = max(0, ($page - 1) * $pageSize);

    $recordsSql = "SELECT * FROM `{$table}` WHERE {$whereSql} ORDER BY `{$pk}` ASC LIMIT {$pageSize} OFFSET {$offset}";
    nu_log("Records query: {$recordsSql} with params: " . json_encode($params), "subform_list");

    try {
        $records = nu_q($recordsSql, $params)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        nu_log("Database error in records fetch query: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), "subform_list");
        throw $e;
    }
    nu_log("Successfully fetched " . count($records) . " records from '{$table}'", "subform_list");

    foreach ($records as &$row) {
        if (isset($row[$pk])) {
            $row['id'] = $row[$pk];
        }
        foreach ($allFields as $field) {
            if (nu_field_type($field) !== 'lookup') continue;
            $fname = nu_field_name($field);
            if ($fname === '') continue;

            // Map the database store field value to the layout field name
            $val = nu_field_value($row, $field);
            $row[$fname] = $val;

            // Render the lookup human-readable display label
            $row[$fname . '_display'] = nu_render_lookup_display($field, $val);
        }
    }
    unset($row);

    nu_json(['success' => true, 'data' => [
        'layout'     => $gridFields,
        'all_fields' => $allFields,
        'records'    => $records,
        'pk'         => $pk,
        'total'      => $total,
        'page'       => $page,
        'page_size'  => $pageSize
    ]]);
}

function nu_handle_subform_save() {
    require_once __DIR__ . '/../core/Conflict.php';
    $code           = $_GET['code']            ?? '';
    $fk             = nu_safe_ident($_GET['fk'] ?? '');
    $parentId       = $_GET['parent_id']       ?? '';
    $parentFormCode = $_GET['parent_form_code'] ?? '';
    $id             = $_GET['id']              ?? '';
    $data           = nu_request_json();

    if ($code === '' || $fk === '') nu_json(['success' => false, 'error' => 'Missing params'], 400);

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    // ── Check Edit Roles purely server side ──
    $sfMeta = nu_get_subform_roles($parentFormCode, $code, $fk);
    $editRoles = $sfMeta['edit'];
    $auth = class_exists('NuAuth') ? NuAuth::getInstance() : null;
    $currentRole = $auth ? $auth->getCurrentRole() : '';
    if ($currentRole === '') {
        $currentRole = $_SESSION['usr_role'] ?? $_SESSION['role'] ?? $_SESSION['access_level'] ?? '';
    }
    if ($editRoles !== '') {
        $allowed = array_map('trim', explode(',', strtolower($editRoles)));
        $currentRoleLower = strtolower($currentRole);
        if (!in_array('*', $allowed, true) && !in_array('admin', $allowed, true) && !in_array('globeadmin', $allowed, true) && !in_array($currentRoleLower, $allowed, true)) {
            nu_json(['success' => false, 'error' => 'Access Denied: Edit Roles restriction.'], 403);
        }
    }

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $fields = nu_flatten_layout(nu_decode_layout($form));

    if ($table === '') nu_json(['success' => false, 'error' => 'No table'], 400);

    $pk     = nu_get_pk($table);
    $pkType = nu_form_pk_type($form);

    if ($id && !empty($data['_record_sigs']) && is_array($data['_record_sigs'])) {
        $currentRecord = nu_get_record($table, $id);
        if (!empty($currentRecord)) {
            $conflictCheck = NuConflict::check($data['_record_sigs'], $currentRecord, $data);
            if ($conflictCheck['hasConflict']) {
                nu_json([
                    'success' => false,
                    'error' => 'Conflict detected: this row was modified by another user since you opened it.',
                    'conflicts' => $conflictCheck['conflicts']
                ], 409);
            }
        }
    }

    // Sync table columns for the subform table at runtime if it is not existing_no_sync
    $tableMode = $form[$c['table_mode']] ?? 'new';
    if ($tableMode !== 'existing_no_sync') {
        if (!nu_table_exists($table)) {
            nu_create_form_table($table, $pkType, $fields);
        } else {
            nu_sync_form_table_columns($table, $fields);
        }
    }

    $save = [];

    $suppliedPk = !empty($data[$pk]) ? $data[$pk] : (!empty($data['id']) ? $data['id'] : null);
    if ($suppliedPk !== null && $suppliedPk !== '') {
        $save[$pk] = $suppliedPk;
    }

    if (!$id) {
        if (empty($save[$pk]) && ($pkType === 'uuid' || nu_is_pk_string_type($table, $pk))) {
            $save[$pk] = nu_generate_uuid();
        }
    }

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        if ($name === '') continue;
        $type = nu_field_type($field);

        if (in_array($type, ['html','heading','divider','fieldset','subform','button'], true)) continue;

        if ($name === $pk) {
            if (!empty($data[$name])) {
                $save[$name] = $data[$name];
            }
            continue;
        }

        if ($type === 'uuid') {
            if (!empty($data[$name])) $save[$name] = $data[$name];
            continue;
        }

        if ($type === 'autonumber') {
            if (!$id) {
                $save[$name] = nu_generate_autonumber($field, $data, $code);
            }
            continue;
        }

        // skip readonly fields on save (both server_readonly and readonly flags)
        if (nu_field_server_readonly($field) || nu_field_is_fk($field) || nu_field_readonly($field)) continue;

        // skip no_duplicate fields on INSERT
        if (!$id && nu_field_no_duplicate($field)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_resolve_lookup_store_col($field);
            if ($dbCol === '') continue;
            $save[$dbCol] = nu_coerce_save_value($data[$name] ?? null);
            continue;
        }

        if ($type === 'checkbox') {
            $save[$name] = !empty($data[$name]) ? 1 : 0;
        } else {
            $save[$name] = nu_coerce_save_value($data[$name] ?? null);
        }
    }

    if ($parentId !== '' && $fk !== '') {
        $save[$fk] = $parentId;
    }

    // Automatically populate system columns
    $tableColumns = nu_get_table_columns($table);
    $currentUserId = $_SESSION['nu_user_id'] ?? $_SESSION['user_id'] ?? null;
    $now = date('Y-m-d H:i:s');

    // Get location
    $locationVal = $_SESSION['nu_user_meta']['location'] ?? $_SESSION['location'] ?? null;
    if ($locationVal === null && $currentUserId !== null) {
        try {
            $stmtLocation = nu_db()->prepare("SELECT usr_custom_fields FROM nu_users WHERE usr_id = ?");
            $stmtLocation->execute([$currentUserId]);
            $rowLocation = $stmtLocation->fetch(PDO::FETCH_ASSOC);
            if ($rowLocation && !empty($rowLocation['usr_custom_fields'])) {
                $customLocation = json_decode($rowLocation['usr_custom_fields'], true);
                if (is_array($customLocation) && isset($customLocation['location'])) {
                    $locationVal = $customLocation['location'];
                }
            }
        } catch (Throwable $e) {}
    }

    if (!$id) {
        // INSERT
        if (isset($tableColumns['created_at'])) $save['created_at'] = $now;
        if (isset($tableColumns['updated_at'])) $save['updated_at'] = $now;
        if ($currentUserId !== null) {
            if (isset($tableColumns['created_by'])) $save['created_by'] = $currentUserId;
            if (isset($tableColumns['updated_by'])) $save['updated_by'] = $currentUserId;
            if (isset($tableColumns['user_id']))    $save['user_id']    = $currentUserId;
        }
        if ($locationVal !== null && isset($tableColumns['location'])) {
            $save['location'] = $locationVal;
        }
    } else {
        // UPDATE
        unset($save['created_at']);
        unset($save['created_by']);
        unset($save['user_id']);
        unset($save['location']);
        if (isset($tableColumns['updated_at'])) $save['updated_at'] = $now;
        if ($currentUserId !== null && isset($tableColumns['updated_by'])) {
            $save['updated_by'] = $currentUserId;
        }
    }

    $save = nu_filter_save_to_columns($save, $table, $pk);

    $saveWithoutPk = array_filter($save, function ($v, $k) use ($pk) { return $k !== $pk; }, ARRAY_FILTER_USE_BOTH);
    if (!$id && !$saveWithoutPk && !($pkType === 'uuid')) {
        nu_json(['success' => false, 'error' => 'No fields to save'], 400);
    }

    $finalId = $id;
    if ($id) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) {
            if ($col === $pk) continue;
            $sets[] = "`{$col}` = ?"; $params[] = $val;
        }
        if (!$sets) nu_json(['success' => false, 'error' => 'No fields to update'], 400);
        $params[] = $id;
        nu_q("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?", $params);
        nu_json(['success' => true, 'id' => $id]);
    } else {
        $cols = array_keys($save);
        $placeholders = array_fill(0, count($cols), '?');
        nu_q("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")", array_values($save));
        $newId = (!empty($save[$pk])) ? $save[$pk] : (($pkType === 'uuid') ? ($save[$pk] ?? nu_db()->lastInsertId()) : nu_db()->lastInsertId());

        // Auto-start workflow if bound to this form code
        try {
            if (!class_exists('WorkflowEngine')) {
                require_once __DIR__ . '/../core/Workflow.php';
            }
            $dbInst = NuDatabase::getInstance();
            $wfBound = $dbInst->fetchOne('SELECT wf_id FROM nu_workflows WHERE LOWER(wf_form_code) = LOWER(:fcode) AND wf_active = 1 LIMIT 1', [':fcode' => $code]);
            if ($wfBound) {
                $userIdVal = (int)($_SESSION['nu_user_id'] ?? ($_SESSION['user_id'] ?? 0));
                if ($userIdVal === 0 && class_exists('NuAuth')) {
                    $authInst = NuAuth::getInstance();
                    if ($authInst->checkAuth()) {
                        $cUser = $authInst->getCurrentUser();
                        $userIdVal = (int)($cUser['usr_id'] ?? 0);
                    }
                }
                $wfEngine = new WorkflowEngine();
                $wfEngine->start((int)$wfBound['wf_id'], $userIdVal, $table, (string)$newId);
            }
        } catch (\Throwable $wfe) {
            error_log('[Auto Workflow Start Error] ' . $wfe->getMessage());
        }

        nu_json(['success' => true, 'id' => $newId]);
    }
}

function nu_handle_subform_delete() {
    $code           = $_GET['code']            ?? '';
    $fk             = nu_safe_ident($_GET['fk'] ?? '');
    $id             = $_GET['id']              ?? '';
    $parentFormCode = $_GET['parent_form_code'] ?? '';

    if ($code === '' || $id === '') nu_json(['success' => false, 'error' => 'Missing params'], 400);

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    // ── Check Edit Roles purely server side ──
    $sfMeta = nu_get_subform_roles($parentFormCode, $code, $fk);
    $editRoles = $sfMeta['edit'];
    $auth = class_exists('NuAuth') ? NuAuth::getInstance() : null;
    $currentRole = $auth ? $auth->getCurrentRole() : '';
    if ($currentRole === '') {
        $currentRole = $_SESSION['usr_role'] ?? $_SESSION['role'] ?? $_SESSION['access_level'] ?? '';
    }
    if ($editRoles !== '') {
        $allowed = array_map('trim', explode(',', strtolower($editRoles)));
        $currentRoleLower = strtolower($currentRole);
        if (!in_array('*', $allowed, true) && !in_array('admin', $allowed, true) && !in_array('globeadmin', $allowed, true) && !in_array($currentRoleLower, $allowed, true)) {
            nu_json(['success' => false, 'error' => 'Access Denied: Edit Roles restriction.'], 403);
        }
    }

    $c     = nu_form_columns();
    $table = nu_safe_ident($form[$c['table']] ?? '');
    if ($table === '') nu_json(['success' => false, 'error' => 'No table'], 400);

    $pk = nu_get_pk($table);
    nu_q("DELETE FROM `{$table}` WHERE `{$pk}` = ?", [$id]);
    nu_json(['success' => true]);
}

/* ── Standard handlers ────────────────────────────────────────────── */

function nu_handle_render() {
    $code = $_GET['code'] ?? '';
    $id   = $_GET['id']   ?? '';
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);
    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);

    $c        = nu_form_columns();
    $formType = strtolower(trim((string)($form[$c['type']] ?? $form['form_type'] ?? '')));

    if ($formType === 'page') {
        require_once __DIR__ . '/../core/NuPage.php';
        $nu = new NuPage();
        $cleanCode = preg_replace('/[^a-zA-Z0-9_-]/', '', $code);
        $pageFile  = __DIR__ . '/../custom/pages/' . $cleanCode . '/index.php';
        if (file_exists($pageFile)) {
            ob_start();
            include $pageFile;
            $pageOutput = ob_get_clean();
            nu_json(['success' => true, 'html' => $pageOutput, 'is_page' => true]);
        } else {
            nu_json(['success' => false, 'error' => 'Custom page index.php not found at custom/pages/' . $cleanCode . '/index.php'], 404);
        }
        return;
    }

    $table  = $form[$c['table']] ?? '';
    $record = $id ? nu_get_record($table, $id) : [];
    nu_json(['success' => true, 'html' => nu_render_form_html($form, $record, $id ?: null)]);
}

function nu_handle_fields() {
    $code = $_GET['code'] ?? '';
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);
    $form   = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);
    $fields = nu_flatten_layout(nu_decode_layout($form));
    $out    = [];
    foreach ($fields as $field) {
        $out[] = [
            'fieldname'               => nu_field_name($field),
            'fieldlabel'              => nu_field_label($field),
            'fieldtype'               => nu_field_type($field),
            'is_fk'                   => nu_field_is_fk($field),
            'hide_in_grid'            => nu_field_hide_in_grid($field),
            'server_readonly'         => nu_field_server_readonly($field),
            'readonly'                => nu_field_readonly($field),
            'hidden'                  => nu_field_hidden($field),
            'hidden_for_normal_users' => nu_field_hidden_for_normal_users($field),
            'no_duplicate'            => nu_field_no_duplicate($field),
        ];
    }
    nu_json(['success' => true, 'data' => $out]);
}

function nu_handle_events() {
    nu_json(['success' => true, 'code' => '']);
}

/**
 * nu_handle_list — browse query with optional role-based browse_php override.
 */
function nu_handle_list() {
    $code = $_GET['code'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $q    = trim($_GET['q'] ?? '');
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);
    $form   = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $layout = nu_decode_layout($form);

    if ($table === '') {
        nu_json(['success' => true, 'data' => [
    'layout'  => nu_flatten_layout_for_grid($layout), 'records' => $records,'page'=>1,'pages'=>1,'total'=>0,
            'query'=>$q,'browsesearchenabled'=>0,'browsesearchplaceholder'=>'Search...'
        ]]);
        
       
    }

    $pageSize = (int)($form[$c['browse_page_size']] ?? 20);
    if ($pageSize < 1) $pageSize = 20;

    $pk         = nu_get_pk($table);
    
    // Support dynamic sorting
    $reqSort = $_GET['sort'] ?? '';
    $reqDir  = strtoupper($_GET['dir'] ?? 'ASC');
    if (!in_array($reqDir, ['ASC', 'DESC'])) $reqDir = 'ASC';

    $sortSql      = trim((string)($form[$c['browse_default_sort']] ?? ''));
    $defaultOrder = $sortSql !== '' ? $sortSql : "`{$table}`.`{$pk}` DESC";

    if ($reqSort !== '') {
        $safeReqSort = nu_safe_ident($reqSort);
        $orderClause = "`{$safeReqSort}` {$reqDir}";
    } else {
        $orderClause = $defaultOrder;
    }

    $browsePhp = trim((string)($form[$c['browse_php']] ?? ''));
    $browseConds = $form[$c['browse_conditions']] ?? [];
    if (is_string($browseConds)) {
        $browseConds = json_decode($browseConds, true) ?: [];
    }

    $nuHash    = nu_get_hash();
    $nuSql     = null;
    $nuParams  = [];
    $nuWhere   = '';
    $nuOrder   = '';
    $nuColumns = '';

    $auth = NuAuth::getInstance();
    $currentRole = $auth->getCurrentRole();

    // 1. Evaluate Role-Based Conditions (JSON)
    if (!empty($browseConds) && is_array($browseConds)) {
        foreach ($browseConds as $cond) {
            if (isset($cond['role']) && ($cond['role'] === '*' || $cond['role'] === $currentRole)) {
                if (!empty($cond['where'])) {
                    $nuWhere = $auth->resolveHashes($cond['where']);
                }
                if (!empty($cond['columns'])) {
                    $nuColumns = trim($cond['columns']);
                }
                break; // Apply only the first matching condition
            }
        }
    }

    // 2. Evaluate browse_php (can override JSON conditions)
    if ($browsePhp !== '') {
        try {
            eval($browsePhp);
        } catch (Throwable $e) {
            nu_log('browse_php error in form ' . $code . ': ' . $e->getMessage(), 'list');
        }
    }

    $searchEnabled = (int)($form[$c['browse_search_enabled']] ?? 0);
    $searchFields  = trim((string)($form[$c['browse_search_fields']] ?? ''));

    $baseSqlForLog = '';
    $finalSqlForLog = '';
    $paramsForLog = [];

    if ($nuSql !== null) {
        $baseSql    = $nuSql;
        $baseParams = $nuParams;

        if ($searchEnabled && $q !== '') {
            $fields = array_filter(array_map('trim', explode(',', $searchFields)));
            if (!$fields) {
                foreach (nu_flatten_layout_for_grid($layout) as $field) {
                    $fname = nu_safe_ident(nu_field_name($field));
                    if ($fname !== '') $fields[] = $fname;
                }
            }
            $likes = [];
            foreach ($fields as $fn) {
                $fn = nu_safe_ident($fn);
                if ($fn === '') continue;
                $likes[] = "`{$fn}` LIKE ?";
                $baseParams[] = '%' . $q . '%';
            }
            if ($likes) {
                $baseSql = "SELECT * FROM ({$baseSql}) _nu_browse WHERE (" . implode(' OR ', $likes) . ")";
            }
        }

        $finalOrder = $nuOrder !== '' ? $nuOrder : $orderClause;
        $total  = (int)nu_q("SELECT COUNT(*) FROM ({$baseSql}) _nu_cnt", $baseParams)->fetchColumn();
        $pages  = max(1, (int)ceil($total / $pageSize));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $pageSize;

        $baseSqlForLog = $baseSql;
        $finalSqlForLog = $baseSql . " ORDER BY {$finalOrder} LIMIT {$pageSize} OFFSET {$offset}";
        $paramsForLog = $baseParams;

        $records = nu_q($finalSqlForLog, $baseParams)
                       ->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $where = []; $params = [];
        $joins = [];

        $selectCols = ["`{$table}`.*"];
        if ($nuColumns !== '') {
            $selectCols = [];
            foreach (explode(',', $nuColumns) as $colName) {
                $colName = nu_safe_ident(trim($colName));
                if ($colName !== '') $selectCols[] = "`{$table}`.`{$colName}`";
            }
            if (empty($selectCols)) $selectCols = ["`{$table}`.*"];
        }

        // Handle custom Joins from layout AND browse_layout
        $flatLayout = nu_flatten_layout_for_grid($layout);
        $bLayoutJson = $form[$c['browse_layout']] ?? '';
        $bLayout = [];
        if (!empty($bLayoutJson)) {
            $bLayout = json_decode($bLayoutJson, true);
            if (!is_array($bLayout)) $bLayout = [];
        }

        // Collect all potential elements to scan for LEFT JOINs
        $scannedFields = array_merge($flatLayout, $bLayout);

        foreach ($scannedFields as $f) {
            $jSql = trim($f['join_sql'] ?? '');
            $jDisp = trim($f['join_display_field'] ?? '');
            $fName = trim((string)($f['name'] ?? ($f['fieldname'] ?? '')));
            if ($jSql !== '' && $jDisp !== '' && $fName !== '') {
                if (!in_array($jSql, $joins, true)) {
                    $joins[] = $jSql;
                }
                $alias = "`{$fName}_display`";
                $projection = "{$jDisp} AS {$alias}";
                if (!in_array($projection, $selectCols, true)) {
                    $selectCols[] = $projection;
                }
            }
        }

        if ($nuWhere !== '') {
            $where[]  = '(' . $nuWhere . ')';
            $params   = array_merge($params, $nuParams);
        }

        if ($searchEnabled && $q !== '') {
            $fields = array_filter(array_map('trim', explode(',', $searchFields)));
            if (!$fields) {
                foreach ($flatLayout as $field) {
                    $fname = nu_safe_ident(nu_field_name($field));
                    if ($fname !== '') $fields[] = $fname;
                }
            }
            $likes = [];
            foreach ($fields as $fn) {
                $fn = nu_safe_ident($fn);
                if ($fn === '') continue;
                // If it's a join display field, we might need to handle it differently, 
                // but usually searching by the alias or original field works if SQL allows.
                // To be safe, we wrap in subquery or use the join field directly if we knew it.
                // For now, let's assume it's a column in the main table or we use the alias.
                $likes[] = "`{$fn}` LIKE ?";
                $params[] = '%' . $q . '%';
            }
            if ($likes) $where[] = '(' . implode(' OR ', $likes) . ')';
        }

        $whereSql    = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $joinSql     = $joins ? (' ' . implode(' ', $joins)) : '';
        $selectSql   = implode(', ', $selectCols);
        
        $finalOrder = $nuOrder !== '' ? $nuOrder : $orderClause;
        
        $totalSql    = "SELECT COUNT(*) FROM `{$table}`" . $joinSql . $whereSql;
        $total       = (int)nu_q($totalSql, $params)->fetchColumn();
        $pages       = max(1, (int)ceil($total / $pageSize));
        if ($page > $pages) $page = $pages;
        $offset      = ($page - 1) * $pageSize;
        
        $baseSqlForLog = "SELECT {$selectSql} FROM `{$table}`" . $joinSql . $whereSql;
        $recordsSql  = $baseSqlForLog . " ORDER BY {$finalOrder} LIMIT {$pageSize} OFFSET {$offset}";
        $finalSqlForLog = $recordsSql;
        $paramsForLog = $params;

        $records     = nu_q($recordsSql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($records as &$row) {
        if (isset($row[$pk])) {
            $row['id'] = $row[$pk];
        }
    }
    unset($row);

    $responseData = [
        'layout'  => nu_flatten_layout_for_grid($layout), 'records' => $records,
        'page'    => $page,   'pages'   => $pages,   'total' => $total,
        'page_size' => $pageSize ?? null,
        'query'   => $q,      'browsesearchenabled'   => $searchEnabled,
        'browsesearchplaceholder' => $form[$c['browse_search_placeholder']] ?? 'Search...',
        'browse_layout' => $form[$c['browse_layout']] ?? null,
        'browse_delete_enabled' => (int)($form[$c['browse_delete_enabled']] ?? 1),
        'form_table' => $form[$c['table']] ?? ''
    ];

    if (strtolower($currentRole) === 'globeadmin') {
        $responseData['browse_sql_debug'] = [
            'base_sql'  => $baseSqlForLog,
            'final_sql' => $finalSqlForLog,
            'params'    => $paramsForLog
        ];
    }

    nu_json(['success' => true, 'data' => $responseData]);
}

function nu_handle_save() {
    require_once __DIR__ . '/../core/Conflict.php';
    $code = $_GET['code'] ?? '';
    $id   = $_GET['id']   ?? '';
    $finalId = $id;
    $data = nu_request_json();
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);

    $form   = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    if ($table === '') nu_json(['success' => false, 'error' => 'No table configured'], 400);

    if ($id && !empty($data['_record_sigs']) && is_array($data['_record_sigs'])) {
        $currentRecord = nu_get_record($table, $id);
        if (!empty($currentRecord)) {
            $conflictCheck = NuConflict::check($data['_record_sigs'], $currentRecord, $data);
            if ($conflictCheck['hasConflict']) {
                nu_json([
                    'success' => false,
                    'error' => 'Conflict detected: this record was modified by another user since you opened it.',
                    'conflicts' => $conflictCheck['conflicts']
                ], 409);
            }
        }
    }

    $pk     = nu_get_pk($table);
    $pkType = nu_form_pk_type($form);
    $fields = nu_flatten_layout(nu_decode_layout($form));

    $save = [];

    $suppliedPk = !empty($data[$pk]) ? $data[$pk] : (!empty($data['id']) ? $data['id'] : null);
    if ($suppliedPk !== null && $suppliedPk !== '') {
        $save[$pk] = $suppliedPk;
    }

    if (!$id) {
        if (empty($save[$pk]) && ($pkType === 'uuid' || nu_is_pk_string_type($table, $pk))) {
            $save[$pk] = nu_generate_uuid();
        }
    }

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        if ($name === '') continue;
        $type = nu_field_type($field);
        if (in_array($type, ['html','heading','divider','fieldset','subform','button'], true)) continue;

        if ($name === $pk) {
            if (!empty($data[$name])) {
                $save[$name] = $data[$name];
            }
            continue;
        }

        if ($type === 'uuid') {
            if (!empty($data[$name])) $save[$name] = $data[$name];
            continue;
        }

        if ($type === 'autonumber') {
            if (!$id) {
                $save[$name] = nu_generate_autonumber($field, $data, $code);
            }
            continue;
        }

        // skip readonly and hidden_for_normal_users (non-admin) on save
        if (nu_field_server_readonly($field)) continue;
        if (nu_field_readonly($field)) continue;
        if (nu_field_hidden_for_normal_users($field) && !nu_current_user_is_admin()) continue;

        // skip no_duplicate fields on INSERT
        if (!$id && nu_field_no_duplicate($field)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_resolve_lookup_store_col($field);
            if ($dbCol === '') continue;
            $save[$dbCol] = nu_coerce_save_value($data[$name] ?? null);
            continue;
        }

        if ($type === 'checkbox') {
            $save[$name] = !empty($data[$name]) ? 1 : 0;
        } else {
            $save[$name] = nu_coerce_save_value($data[$name] ?? null);
        }
    }

    // Automatically populate system columns
    $tableColumns = nu_get_table_columns($table);
    $currentUserId = $_SESSION['nu_user_id'] ?? $_SESSION['user_id'] ?? null;
    $now = date('Y-m-d H:i:s');

    // Get location
    $locationVal = $_SESSION['nu_user_meta']['location'] ?? $_SESSION['location'] ?? null;
    if ($locationVal === null && $currentUserId !== null) {
        try {
            $stmtLocation = nu_db()->prepare("SELECT usr_custom_fields FROM nu_users WHERE usr_id = ?");
            $stmtLocation->execute([$currentUserId]);
            $rowLocation = $stmtLocation->fetch(PDO::FETCH_ASSOC);
            if ($rowLocation && !empty($rowLocation['usr_custom_fields'])) {
                $customLocation = json_decode($rowLocation['usr_custom_fields'], true);
                if (is_array($customLocation) && isset($customLocation['location'])) {
                    $locationVal = $customLocation['location'];
                }
            }
        } catch (Throwable $e) {}
    }

    if (!$id) {
        // INSERT
        if (isset($tableColumns['created_at'])) $save['created_at'] = $now;
        if (isset($tableColumns['updated_at'])) $save['updated_at'] = $now;
        if ($currentUserId !== null) {
            if (isset($tableColumns['created_by'])) $save['created_by'] = $currentUserId;
            if (isset($tableColumns['updated_by'])) $save['updated_by'] = $currentUserId;
            if (isset($tableColumns['user_id']))    $save['user_id']    = $currentUserId;
        }
        if ($locationVal !== null && isset($tableColumns['location'])) {
            $save['location'] = $locationVal;
        }
    } else {
        // UPDATE
        unset($save['created_at']);
        unset($save['created_by']);
        unset($save['user_id']);
        unset($save['location']);
        if (isset($tableColumns['updated_at'])) $save['updated_at'] = $now;
        if ($currentUserId !== null && isset($tableColumns['updated_by'])) {
            $save['updated_by'] = $currentUserId;
        }
    }

    $save = nu_filter_save_to_columns($save, $table, $pk);

    $saveWithoutPk = array_filter($save, function ($v, $k) use ($pk) { return $k !== $pk; }, ARRAY_FILTER_USE_BOTH);
    if (!$id && empty($saveWithoutPk) && $pkType !== 'uuid') {
        nu_json(['success' => false, 'error' => 'No fields to save'], 400);
    }

    if ($id) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) {
            if ($col === $pk) continue;
            $sets[] = "`{$col}` = ?"; $params[] = $val;
        }
        if (!$sets) nu_json(['success' => false, 'error' => 'No fields to update'], 400);
        $params[] = $id;
        nu_q("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?", $params);
        $finalId = $id;

        // Trigger Outgoing Webhooks for form_update
        try {
            require_once __DIR__ . '/../core/WebhookSender.php';
            NuWebhookSender::trigger('form_update', [
                'table'     => $table,
                'record_id' => $finalId,
                'data'      => array_merge($save, ['id' => $finalId])
            ]);
        } catch (\Throwable $whe) {
            error_log('[Webhook Dynamic Form Update Trigger Error] ' . $whe->getMessage());
        }
    } else {
        $cols = array_keys($save);
        $placeholders = array_fill(0, count($cols), '?');
        nu_q("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")", array_values($save));
        $finalId = (!empty($save[$pk])) ? $save[$pk] : (($pkType === 'uuid') ? ($save[$pk] ?? nu_db()->lastInsertId()) : nu_db()->lastInsertId());

        // Trigger Outgoing Webhooks for form_insert
        try {
            require_once __DIR__ . '/../core/WebhookSender.php';
            NuWebhookSender::trigger('form_insert', [
                'table'     => $table,
                'record_id' => $finalId,
                'data'      => array_merge($save, ['id' => $finalId])
            ]);
        } catch (\Throwable $whe) {
            error_log('[Webhook Dynamic Form Insert Trigger Error] ' . $whe->getMessage());
        }
    }

    // Run Custom PHP After Save if configured
    $customPhpAfter = trim((string)($form[$c['custom_php_after']] ?? ''));
    if ($customPhpAfter !== '') {
        try {
            // Prepare record values map
            $record = array_merge($save, ['id' => $finalId]);
            $record['record_id'] = $finalId;

            // Populate variables for each field name
            foreach ($record as $k => $v) {
                if (preg_match('/^[a-zA-Z0-9_]+$/', $k)) {
                    $$k = $v;
                }
            }

            // Also replace #field_name# in code
            $codeToEval = preg_replace_callback('/#([a-zA-Z0-9_]+)#/', function($matches) use ($record) {
                $fieldName = $matches[1];
                $val = $record[$fieldName] ?? '';
                return addslashes((string)$val);
            }, $customPhpAfter);

            // Execute the code
            eval($codeToEval);
        } catch (Throwable $e) {
            nu_log('custom_php_after error in form ' . $code . ': ' . $e->getMessage(), 'save');
        }
    }

    nu_json([
        'success' => true,
        'id' => $finalId,
        '_record_sigs' => NuConflict::rowSigs(nu_get_record($table, $finalId) ?: [])
    ]);
}

function nu_handle_save_form() {
    $data = nu_request_json();

    $formId   = (int)($data['form_id'] ?? 0);
    $c        = nu_form_columns();
    $ftable   = nu_form_table_name();

    $name      = trim((string)($data['form_name']   ?? ''));
    $code      = trim((string)($data['form_code']   ?? ''));
    $table     = trim((string)($data['form_table']  ?? ''));
    $layout    = $data['form_layout'] ?? [];
    $tableMode = trim((string)($data['form_table_mode'] ?? 'new'));
    $pkType    = trim((string)($data['form_pk_type'] ?? 'autoincrement'));

    nu_log('save_form called — name="' . $name . '" code="' . $code . '" table="' . $table . '" table_mode="' . $tableMode . '" pk_type="' . $pkType . '"', 'save_form');

    if ($name === '') nu_json(['success' => false, 'error' => 'form_name is required'], 400);

    if ($code === '') {
        $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $code = trim($code, '_');
    }

    $save = [
        $c['name']                     => $name,
        $c['code']                     => $code,
        $c['table']                    => $table,
        $c['layout']                   => json_encode(is_array($layout) ? $layout : []),
        $c['active']                   => 1,
        $c['type']                     => $data['form_type']           ?? 'main',
        $c['display_mode']             => $data['browse_display_mode'] ?? 'inline',
        $c['pk_type']                  => $pkType,
        $c['table_mode']               => $tableMode,
        $c['custom_js']                => $data['form_custom_js']      ?? '',
        $c['js_before_save']           => $data['form_js_before_save'] ?? '',
        $c['js_after_save']            => $data['form_js_after_save']  ?? '',
        $c['custom_php']               => $data['form_custom_php']     ?? '',
        $c['custom_css']               => $data['form_custom_css']     ?? '',
        $c['browse_sql']               => $data['browse_sql']          ?? '',
        $c['browse_columns']           => $data['browse_columns']      ?? '',
        $c['browse_search_enabled']    => !empty($data['browse_search_enabled']) ? 1 : 0,
        $c['browse_search_placeholder']=> $data['browse_search_placeholder'] ?? 'Search...',
        $c['browse_search_fields']     => $data['browse_search_fields']     ?? '',
        $c['browse_page_size']         => (int)($data['browse_page_size']   ?? 20),
        $c['browse_default_sort']      => $data['browse_default_sort']      ?? '',
        $c['browse_php']               => $data['browse_php']               ?? '',
        $c['browse_layout']            => is_array($data['browse_layout'] ?? null) ? json_encode($data['browse_layout']) : ($data['browse_layout'] ?? ''),
        $c['browse_delete_enabled']    => isset($data['browse_delete_enabled']) ? (int)$data['browse_delete_enabled'] : 1,
    ];

    $formTableCols = nu_get_table_columns($ftable);
    if (!empty($formTableCols)) {
        $save = array_intersect_key($save, $formTableCols);
    }

    if ($formId > 0) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) {
            $sets[]   = "`{$col}` = ?";
            $params[] = $val;
        }
        $params[] = $formId;
        $pkCol = $c['id'];
        nu_q("UPDATE `{$ftable}` SET " . implode(', ', $sets) . " WHERE `{$pkCol}` = ?", $params);
        nu_log('Updated form row id=' . $formId, 'save_form');
    } else {
        $cols         = array_keys($save);
        $placeholders = array_fill(0, count($cols), '?');
        nu_q(
            "INSERT INTO `{$ftable}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")",
            array_values($save)
        );
        $formId = (int)nu_db()->lastInsertId();
        nu_log('Inserted form row id=' . $formId, 'save_form');
    }

    $ddlResult  = ['created' => false, 'added' => [], 'errors' => []];
    $safeTable  = nu_safe_ident($table);
    $flatFields = nu_flatten_layout(is_array($layout) ? $layout : []);

    if ($safeTable === '') {
        nu_log('No form_table specified — skipping DDL for form_id=' . $formId, 'save_form');
    } elseif ($tableMode === 'existing_no_sync') {
        nu_log('table_mode=existing_no_sync — skipping DDL for table ' . $safeTable, 'save_form');
    } elseif (!nu_table_exists($safeTable)) {
        nu_log('Table does not exist, will CREATE: ' . $safeTable, 'save_form');
        $createResult = nu_create_form_table($safeTable, $pkType, $flatFields);
        $ddlResult['created'] = $createResult['created'];
        if ($createResult['error']) {
            $ddlResult['errors'][] = $createResult['error'];
            nu_log('Table creation error for ' . $safeTable . ': ' . $createResult['error'], 'save_form');
        }
    } else {
        nu_log('Table exists, syncing columns for: ' . $safeTable, 'save_form');
        $syncResult = nu_sync_form_table_columns($safeTable, $flatFields);
        $ddlResult['added']  = $syncResult['added'];
        $ddlResult['errors'] = $syncResult['errors'];
        if ($syncResult['errors']) {
            nu_log('Column sync errors for ' . $safeTable . ': ' . implode('; ', $syncResult['errors']), 'save_form');
        }
    }

    nu_json([
        'success'        => true,
        'form_id'        => $formId,
        'form_code'      => $code,
        'table_created'  => $ddlResult['created'],
        'columns_added'  => $ddlResult['added'],
        'ddl_errors'     => $ddlResult['errors'],
    ]);
}

function nu_handle_load_form() {
    $formId = (int)($_GET['form_id'] ?? 0);
    if ($formId <= 0) nu_json(['success' => false, 'error' => 'Missing form_id'], 400);

    $ftable = nu_form_table_name();
    $c      = nu_form_columns();
    $pk     = $c['id'];

    $form = nu_q("SELECT * FROM `{$ftable}` WHERE `{$pk}` = ? LIMIT 1", [$formId])
                ->fetch(PDO::FETCH_ASSOC);

    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);

    nu_json(['success' => true, 'data' => [
        'form_id'                  => (int)$form[$c['id']],
        'form_name'                => $form[$c['name']]                      ?? '',
        'form_code'                => $form[$c['code']]                      ?? '',
        'form_table'               => $form[$c['table']]                     ?? '',
        'form_layout'              => json_decode($form[$c['layout']] ?? '[]', true) ?: [],
        'form_type'                => $form[$c['type']]                      ?? 'main',
        'browse_display_mode'      => $form[$c['display_mode']]              ?? 'inline',
        'form_pk_type'             => $form[$c['pk_type']]                   ?? 'autoincrement',
        'form_table_mode'          => $form[$c['table_mode']]                ?? 'new',
        'form_custom_js'           => $form[$c['custom_js']]                 ?? '',
        'form_js_before_save'      => $form[$c['js_before_save']]            ?? '',
        'form_js_after_save'       => $form[$c['js_after_save']]             ?? '',
        'form_custom_php'          => $form[$c['custom_php']]                ?? '',
        'form_custom_css'          => $form[$c['custom_css']]                ?? '',
        'browse_sql'               => $form[$c['browse_sql']]                ?? '',
        'browse_columns'           => $form[$c['browse_columns']]            ?? '',
        'browse_search_enabled'    => (int)($form[$c['browse_search_enabled']]  ?? 0),
        'browse_search_placeholder'=> $form[$c['browse_search_placeholder']]  ?? 'Search...',
        'browse_search_fields'     => $form[$c['browse_search_fields']]      ?? '',
        'browse_page_size'         => (int)($form[$c['browse_page_size']]    ?? 20),
        'browse_default_sort'      => $form[$c['browse_default_sort']]       ?? '',
        'browse_php'               => $form[$c['browse_php']]                ?? '',
        'browse_conditions'        => is_array($form[$c['browse_conditions']] ?? null) ? $form[$c['browse_conditions']] : (json_decode($form[$c['browse_conditions']] ?? '[]', true) ?: []),
    ]]);
}

/* ── Router ─────────────────────────────────────────── */
if (PHP_SAPI !== 'cli' || isset($_GET['action'])) {
    try {
        $action = $_GET['action'] ?? '';
        nu_log('action=' . $action, 'router');
        switch ($action) {
            case 'render':           nu_handle_render();          break;
            case 'fields':           nu_handle_fields();          break;
            case 'events':           nu_handle_events();          break;
            case 'list':             nu_handle_list();            break;
            case 'save':             nu_handle_save();            break;
            case 'save_form':        nu_handle_save_form();       break;
            case 'load_form':        nu_handle_load_form();       break;
            case 'subform_fields':   nu_handle_subform_fields();  break;
            case 'subform_list':     nu_handle_subform_list();    break;
            case 'subform_save':     nu_handle_subform_save();    break;
            case 'subform_delete':   nu_handle_subform_delete();  break;
            default:
                nu_log('Unknown action: ' . $action, 'router');
                nu_json(['success' => false, 'error' => 'Invalid action'], 400);
        }
    } catch (Throwable $e) {
        nu_log('Unhandled exception in action=' . ($_GET['action'] ?? '') . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'router');
        nu_json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}
