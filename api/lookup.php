<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Accept id_col / id_column / id  (JS sends id_col)
$table      = $_GET['table']       ?? '';
$idCol      = $_GET['id_col']      ?? $_GET['id_column'] ?? $_GET['id'] ?? '';
$displayCol = $_GET['display_col'] ?? $_GET['display_column'] ?? $_GET['display'] ?? '';
$filter     = $_GET['filter']      ?? '';
$extra      = $_GET['extra']       ?? '';
$q          = trim($_GET['q']      ?? '');

if (!$table || preg_match('/[^a-zA-Z0-9_]/', $table)) {
    echo json_encode(['success' => false, 'error' => 'Invalid table name']);
    exit;
}

// Lookups only need the project check (reference tables often have no form of their own); platform tables stay off limits.
require_once '../core/ProjectContext.php';
require_once '../core/CrudGuard.php';
$guardErr = NuCrudGuard::authorizeTable($table, 'view', strtolower((string)(($auth->getCurrentUser()['usr_role'] ?? ''))), ProjectContext::getId(), false);
if ($guardErr !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $guardErr]);
    exit;
}

$db = NuDatabase::getInstance();

function getPrimaryKeyColumn($db, $table) {
    try {
        $result = $db->fetchAll("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        if (!empty($result) && !empty($result[0]['Column_name'])) {
            return $result[0]['Column_name'];
        }
    } catch (Exception $e) {}
    return 'id';
}

/**
 * When no display column is configured, pick the first non-PK column
 * from the table so the query never references a column that doesn't exist.
 */
function getFirstDisplayColumn($db, $table, $pkCol) {
    try {
        $cols = $db->fetchAll("SHOW COLUMNS FROM `{$table}`");
        foreach ($cols as $col) {
            $colName = $col['Field'] ?? '';
            if ($colName !== '' && $colName !== $pkCol) {
                return $colName;
            }
        }
    } catch (Exception $e) {}
    return $pkCol; // last resort: just show the PK value
}

if (empty($idCol)) {
    $idCol = getPrimaryKeyColumn($db, $table);
}

// If display column was not passed or was empty, auto-detect it
if ($displayCol === '') {
    $displayCol = getFirstDisplayColumn($db, $table, $idCol);
}

if (preg_match('/[^a-zA-Z0-9_]/', $idCol) || preg_match('/[^a-zA-Z0-9_]/', $displayCol)) {
    echo json_encode(['success' => false, 'error' => 'Invalid column name']);
    exit;
}

// Extra mapping cols (src_col:target_field,...)
$extraCols = [];
if (!empty($extra)) {
    foreach (explode(',', $extra) as $pair) {
        $parts = explode(':', $pair);
        if (count($parts) === 2) {
            $sourceCol = trim($parts[0]);
            if ($sourceCol !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $sourceCol)) {
                $extraCols[] = $sourceCol;
            }
        }
    }
}

$selectCols = ["`{$idCol}`", "`{$displayCol}`"];
foreach ($extraCols as $col) {
    if ($col !== $idCol && $col !== $displayCol) {
        $selectCols[] = "`{$col}`";
    }
}

// Build WHERE clauses
$whereParts = [];
$params     = [];

// Static filter from field config
$filter = trim($filter);
if (stripos($filter, 'WHERE ') === 0) {
    $filter = trim(substr($filter, 6));
}
if ($filter !== '') {
    $whereParts[] = "({$filter})";
}

// Live search query
if ($q !== '') {
    $whereParts[] = "`{$displayCol}` LIKE ?";
    $params[]     = '%' . $q . '%';
}

$sql = 'SELECT ' . implode(', ', $selectCols) . " FROM `{$table}`";
if (!empty($whereParts)) {
    $sql .= ' WHERE ' . implode(' AND ', $whereParts);
}
$sql .= " ORDER BY `{$displayCol}` LIMIT 200";

try {
    $data = empty($params)
        ? $db->fetchAll($sql)
        : $db->fetchAll($sql, $params);

    echo json_encode([
        'success' => true,
        'data'    => $data,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'sql'     => $sql,
    ]);
}
