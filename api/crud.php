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
        echo json_encode(['success' => false, 'error' => $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']]);
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

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
if (file_exists(__DIR__ . '/../core/Auth.php')) {
    require_once __DIR__ . '/../core/Auth.php';
}

$auth = NuAuth::getInstance();
$auth->requireAuth();

$reqTable = $_REQUEST['table'] ?? $_REQUEST['t'] ?? '';
if ($reqTable !== '' && str_starts_with(strtolower((string)$reqTable), 'nu_')) {
    $currentUser = $auth->getCurrentUser();
    $userRole = strtolower((string)($currentUser['usr_role'] ?? $_SESSION['nu_role'] ?? $_SESSION['usr_role'] ?? ''));
    if ($userRole !== 'globeadmin') {
        nu_json(['success' => false, 'error' => 'Direct CRUD access to core system metadata tables is restricted.'], 403);
    }
}

/**
 * nu_db() - always uses NuDatabase singleton, never static call.
 */
function nu_db() {
    $instance = NuDatabase::getInstance();
    return $instance->getConnection();
}

function nu_q($sql, $params = []) {
    $stmt = nu_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function nu_request_json() {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function nu_safe_table($table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') throw new Exception('Table required');
    return $table;
}

function nu_safe_column($column) {
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($column === '') throw new Exception('Invalid column');
    return $column;
}

function nu_parse_where($whereRaw) {
    $whereRaw = trim((string)$whereRaw);
    if ($whereRaw === '') return [[], []];
    $parts = explode('=', $whereRaw, 2);
    if (count($parts) !== 2) throw new Exception('Invalid where format. Use field=value');
    $field = nu_safe_column(trim($parts[0]));
    $value = trim($parts[1]);
    return [["`{$field}` = ?"], [$value]];
}

/**
 * Dynamically resolve the primary key column for any table.
 * Falls back to 'id' if no PRIMARY KEY is found.
 */
function nu_get_pk(string $table): string {
    try {
        $stmt = nu_db()->prepare("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && !empty($row['Column_name'])) ? $row['Column_name'] : 'id';
    } catch (Throwable $e) {
        return 'id';
    }
}

function nu_get_record($table, $id) {
    $pk   = nu_get_pk($table);
    $stmt = nu_q("SELECT * FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1", [$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function nu_list_records($table, $whereRaw, $extraFilters, $page, $perPage) {
    $pk     = nu_get_pk($table);
    $where  = [];
    $params = [];
    list($whereParts, $whereParams) = nu_parse_where($whereRaw);
    $where  = array_merge($where, $whereParts);
    $params = array_merge($params, $whereParams);
    foreach ($extraFilters as $key => $value) {
        if ($value === '' || $value === null) continue;
        $col      = nu_safe_column($key);
        $where[]  = "`{$col}` = ?";
        $params[] = $value;
    }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $page     = max(1, (int)$page);
    $perPage  = max(1, min(200, (int)$perPage));
    $offset   = ($page - 1) * $perPage;
    $total    = (int)nu_q("SELECT COUNT(*) FROM `{$table}`" . $whereSql, $params)->fetchColumn();
    $pages    = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; $offset = ($page - 1) * $perPage; }
    $rows = nu_q("SELECT * FROM `{$table}`" . $whereSql . " ORDER BY `{$pk}` DESC LIMIT {$perPage} OFFSET {$offset}", $params)->fetchAll(PDO::FETCH_ASSOC);
    return ['records' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
}

function nu_get_table_columns_local($table) {
    try {
        $stmt = nu_db()->prepare("DESCRIBE `{$table}`");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $row) {
            $cols[$row['Field']] = true;
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function nu_create_record($table, $data) {
    if (!$data || !is_array($data)) throw new Exception('No data provided');
    $clean = [];
    foreach ($data as $key => $value) $clean[nu_safe_column($key)] = $value;
    if (!$clean) throw new Exception('No valid fields provided');

    // Automatically populate system columns if they exist in the table
    $tableColumns = nu_get_table_columns_local($table);
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

    if (isset($tableColumns['created_at'])) $clean['created_at'] = $now;
    if (isset($tableColumns['updated_at'])) $clean['updated_at'] = $now;
    if ($currentUserId !== null) {
        if (isset($tableColumns['created_by'])) $clean['created_by'] = $currentUserId;
        if (isset($tableColumns['updated_by'])) $clean['updated_by'] = $currentUserId;
        if (isset($tableColumns['user_id']))    $clean['user_id']    = $currentUserId;
    }
    if ($locationVal !== null && isset($tableColumns['location'])) {
        $clean['location'] = $locationVal;
    }

    $cols  = array_keys($clean);
    $sql   = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    nu_q($sql, array_values($clean));
    $newId = nu_db()->lastInsertId();
    try {
        require_once __DIR__ . '/../core/WebhookSender.php';
        NuWebhookSender::trigger('form_insert', [
            'table'     => $table,
            'record_id' => $newId,
            'data'      => array_merge($clean, ['id' => $newId])
        ]);
    } catch (\Throwable $whe) {
        error_log('[Webhook API Insert Trigger Error] ' . $whe->getMessage());
    }
    try {
        if (class_exists('NuAudit')) {
            $audit = new NuAudit();
            $audit->logChange('create', $table, $newId, null, $clean);
        }
    } catch (\Throwable $t) {}
    return $newId;
}

function nu_update_record($table, $id, $data) {
    if (!$id) throw new Exception('ID required');
    if (!$data || !is_array($data)) throw new Exception('No data provided');

    $tableColumns = nu_get_table_columns_local($table);
    $currentUserId = $_SESSION['nu_user_id'] ?? $_SESSION['user_id'] ?? null;
    $now = date('Y-m-d H:i:s');

    $clean = [];
    foreach ($data as $key => $value) {
        $clean[nu_safe_column($key)] = $value;
    }

    // Preserve created_at, created_by, user_id, and location on update
    unset($clean['created_at']);
    unset($clean['created_by']);
    unset($clean['user_id']);
    unset($clean['location']);

    if (isset($tableColumns['updated_at'])) {
        $clean['updated_at'] = $now;
    }
    if ($currentUserId !== null && isset($tableColumns['updated_by'])) {
        $clean['updated_by'] = $currentUserId;
    }

    $old = nu_get_record($table, $id);
    $pk  = nu_get_pk($table);
    $sets   = []; $params = [];
    foreach ($clean as $key => $value) {
        $sets[] = "`" . nu_safe_column($key) . "` = ?";
        $params[] = $value;
    }
    if (!$sets) throw new Exception('No valid fields provided');
    $params[] = $id;
    nu_q("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?", $params);
    try {
        require_once __DIR__ . '/../core/WebhookSender.php';
        NuWebhookSender::trigger('form_update', [
            'table'     => $table,
            'record_id' => $id,
            'data'      => array_merge($clean, ['id' => $id])
        ]);
    } catch (\Throwable $whe) {
        error_log('[Webhook API Update Trigger Error] ' . $whe->getMessage());
    }
    try {
        if (class_exists('NuAudit')) {
            $audit = new NuAudit();
            $audit->logChange('update', $table, $id, $old, $clean);
        }
    } catch (\Throwable $t) {}
    return true;
}

function nu_delete_record($table, $id) {
    if (!$id) throw new Exception('ID required');
    $old = nu_get_record($table, $id);
    $pk  = nu_get_pk($table);
    nu_q("DELETE FROM `{$table}` WHERE `{$pk}` = ?", [$id]);
    try {
        require_once __DIR__ . '/../core/WebhookSender.php';
        NuWebhookSender::trigger('form_delete', [
            'table'     => $table,
            'record_id' => $id,
            'data'      => ['id' => $id]
        ]);
    } catch (\Throwable $whe) {
        error_log('[Webhook API Delete Trigger Error] ' . $whe->getMessage());
    }
    try {
        if (class_exists('NuAudit')) {
            $audit = new NuAudit();
            $audit->logChange('delete', $table, $id, $old, null);
        }
    } catch (\Throwable $t) {}
    return true;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $table  = nu_safe_table($_GET['table'] ?? '');
    $id     = $_GET['id'] ?? null;

    switch ($method) {
        case 'GET':
            if ($id !== null && $id !== '') {
                $record = nu_get_record($table, $id);
                if (in_array($table, ['nu_users', 'nu_user_meta', 'nu_password_history', 'nu_api_tokens'], true)) {
                    try {
                        if (class_exists('NuAudit')) {
                            $audit = new NuAudit();
                            $audit->logChange('read', $table, $id, null, null, ['fields' => array_keys($record ?: [])]);
                        }
                    } catch (\Throwable $t) {}
                }
                nu_json(['success' => true, 'data' => $record, 'record' => $record]);
            }
            $filters = $_GET;
            unset($filters['table'], $filters['id'], $filters['page'], $filters['per_page'], $filters['where'], $filters['_']);
            $result = nu_list_records($table, $_GET['where'] ?? '', $filters, $_GET['page'] ?? 1, $_GET['per_page'] ?? 20);
            nu_json(['success' => true, 'data' => $result['records'], 'records' => $result['records'], 'page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total']]);
            break;

        case 'POST':
            $data  = nu_request_json();
            $newId = nu_create_record($table, $data);
            nu_json(['success' => true, 'id' => $newId, 'message' => 'Created successfully']);
            break;

        case 'PUT':
            $data = nu_request_json();
            nu_update_record($table, $id, $data);
            nu_json(['success' => true, 'id' => $id, 'message' => 'Updated successfully']);
            break;

        case 'DELETE':
            nu_delete_record($table, $id);
            nu_json(['success' => true, 'id' => $id, 'message' => 'Deleted successfully']);
            break;

        default:
            nu_json(['success' => false, 'error' => 'Method not allowed'], 405);
    }
} catch (Throwable $e) {
    nu_json(['success' => false, 'error' => $e->getMessage()], 500);
}
