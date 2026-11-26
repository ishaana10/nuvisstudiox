<?php
declare(strict_types=1);

require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Audit.php';
require_once '../core/ProjectContext.php';
require_once '../core/CrudGuard.php';

header('Content-Type: application/json');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();
$role = strtolower((string)($currentUser['usr_role'] ?? ''));
if ($role !== 'globeadmin' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied: Administrator role required.']);
    exit;
}

$db = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

// Helper: Get data type categorization for casting
function get_column_data_type(string $mysqlType): string {
    $mysqlType = strtolower($mysqlType);
    if (strpos($mysqlType, 'tinyint(1)') !== false) {
        return 'boolean';
    }
    if (
        strpos($mysqlType, 'int') !== false ||
        strpos($mysqlType, 'float') !== false ||
        strpos($mysqlType, 'double') !== false ||
        strpos($mysqlType, 'decimal') !== false ||
        strpos($mysqlType, 'numeric') !== false
    ) {
        return 'numeric';
    }
    if (strpos($mysqlType, 'datetime') !== false || strpos($mysqlType, 'timestamp') !== false) {
        return 'datetime';
    }
    if (strpos($mysqlType, 'date') !== false) {
        return 'date';
    }
    return 'text';
}

// Helper: Parse dates in flexible formats
function parse_import_date(string $str, string $type): ?string {
    $str = trim($str);
    if ($str === '') return null;

    $timestamp = strtotime($str);
    if ($timestamp === false) {
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $str, $matches)) {
            $part1 = (int)$matches[1];
            $part2 = (int)$matches[2];
            $year  = (int)$matches[3];
            if ($part1 > 12) {
                $timestamp = mktime(0, 0, 0, $part2, $part1, $year);
            } else {
                $timestamp = mktime(0, 0, 0, $part1, $part2, $year);
            }
        }
    }

    if ($timestamp !== false) {
        return $type === 'date' ? date('Y-m-d', $timestamp) : date('Y-m-d H:i:s', $timestamp);
    }
    return $str;
}

// Helper: Parse boolean strings
function parse_import_boolean(string $str): ?int {
    $str = strtolower(trim($str));
    if ($str === '') return null;
    $truthy = ['yes', 'true', 'y', '1', 'active', 't', 'on'];
    $falsy  = ['no', 'false', 'n', '0', 'inactive', 'f', 'off'];
    if (in_array($str, $truthy, true)) return 1;
    if (in_array($str, $falsy, true)) return 0;
    return null;
}

// Helper: Parse numeric formatting (currency, commas)
function parse_import_numeric(string $str): float|int|null {
    $str = trim($str);
    if ($str === '') return null;
    $cleaned = preg_replace('/[^\d\.\-]/', '', $str);
    if ($cleaned === '' || $cleaned === '-') return null;
    return strpos($cleaned, '.') !== false ? (float)$cleaned : (int)$cleaned;
}

switch ($action) {
    case 'get_columns': {
        $table = preg_replace('/[^a-z0-9_]/', '', $_GET['table'] ?? '');
        if (!$table) {
            echo json_encode(['success' => false, 'error' => 'Table name is required']);
            exit;
        }

        // Security check for sensitive system tables
        $sensitiveTables = ['nu_users', 'nu_forms', 'nu_menus', 'nu_roles', 'nu_permissions', 'nu_role_permissions'];
        if (in_array($table, $sensitiveTables, true) && $role !== 'globeadmin') {
            echo json_encode(['success' => false, 'error' => 'Access denied: Only globeadmin can access system schemas.']);
            exit;
        }
        // Project isolation and all other nu_ tables (admins included); form permissions are not required for admin import.
        $guardErr = NuCrudGuard::authorizeTable($table, 'view', $role, ProjectContext::getId(), false);
        if ($guardErr !== null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $guardErr]);
            exit;
        }

        try {
            // Verify table exists
            $tables = array_map('current', $db->fetchAll("SHOW TABLES"));
            if (!in_array($table, $tables, true)) {
                echo json_encode(['success' => false, 'error' => "Table '{$table}' does not exist."]);
                exit;
            }

            $rawCols = $db->fetchAll("SHOW COLUMNS FROM `{$table}`");
            $columns = [];
            foreach ($rawCols as $col) {
                $columns[] = [
                    'name'     => $col['Field'],
                    'type'     => get_column_data_type($col['Type']),
                    'raw_type' => $col['Type'],
                    'nullable' => strtoupper($col['Null']) === 'YES',
                ];
            }

            echo json_encode(['success' => true, 'columns' => $columns]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    }

    case 'import':
    default: {
        $table = preg_replace('/[^a-z0-9_]/', '', $_POST['table'] ?? '');
        if (!$table || !isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'Table and CSV file required']);
            exit;
        }

        // Security check for sensitive system tables
        $sensitiveTables = ['nu_users', 'nu_forms', 'nu_menus', 'nu_roles', 'nu_permissions', 'nu_role_permissions'];
        if (in_array($table, $sensitiveTables, true) && $role !== 'globeadmin') {
            echo json_encode(['success' => false, 'error' => 'Access denied: Only globeadmin can modify system tables.']);
            exit;
        }
        // Project isolation and all other nu_ tables (admins included); form permissions are not required for admin import.
        $guardErr = NuCrudGuard::authorizeTable($table, 'add', $role, ProjectContext::getId(), false);
        if ($guardErr !== null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $guardErr]);
            exit;
        }

        $file = $_FILES['file'];
        if ($file['type'] !== 'text/csv' && !str_ends_with(strtolower($file['name']), '.csv')) {
            echo json_encode(['success' => false, 'error' => 'CSV file required']);
            exit;
        }

        // Parse mapping
        $mappingRaw = $_POST['mapping'] ?? '{}';
        $mapping = json_decode($mappingRaw, true);
        if (!is_array($mapping)) {
            echo json_encode(['success' => false, 'error' => 'Invalid mapping configuration']);
            exit;
        }

        try {
            // Verify table exists
            $tables = array_map('current', $db->fetchAll("SHOW TABLES"));
            if (!in_array($table, $tables, true)) {
                echo json_encode(['success' => false, 'error' => "Table '{$table}' does not exist."]);
                exit;
            }

            // Retrieve column schema for target table
            $rawCols = $db->fetchAll("SHOW COLUMNS FROM `{$table}`");
            $schemaMap = [];
            foreach ($rawCols as $col) {
                $schemaMap[$col['Field']] = [
                    'type'     => get_column_data_type($col['Type']),
                    'nullable' => strtoupper($col['Null']) === 'YES',
                ];
            }

            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                echo json_encode(['success' => false, 'error' => 'Failed to open uploaded file']);
                exit;
            }

            // Parse headers
            $headers = fgetcsv($handle);
            if (!$headers) {
                echo json_encode(['success' => false, 'error' => 'CSV file is empty']);
                fclose($handle);
                exit;
            }

            $imported = 0;
            $failed = 0;
            $errors = [];

            $db->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                // Ignore empty or malformed rows
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                // Align row lengths with headers
                if (count($row) !== count($headers)) {
                    $failed++;
                    $errors[] = "Row length mismatch (Expected " . count($headers) . ", got " . count($row) . ")";
                    continue;
                }

                $csvData = array_combine($headers, $row);
                $insertData = [];

                foreach ($mapping as $csvHeader => $dbCol) {
                    if (!$dbCol || $dbCol === '__skip__') {
                        continue;
                    }

                    if (!isset($schemaMap[$dbCol])) {
                        continue; // Column not in target table schema
                    }

                    $rawVal = isset($csvData[$csvHeader]) ? (string)$csvData[$csvHeader] : '';
                    $colSchema = $schemaMap[$dbCol];
                    $colType   = $colSchema['type'];
                    $nullable  = $colSchema['nullable'];

                    $castedValue = null;

                    if ($colType === 'numeric') {
                        $castedValue = parse_import_numeric($rawVal);
                        if ($castedValue === null && !$nullable) {
                            $castedValue = 0;
                        }
                    } elseif ($colType === 'boolean') {
                        $castedValue = parse_import_boolean($rawVal);
                        if ($castedValue === null && !$nullable) {
                            $castedValue = 0;
                        }
                    } elseif ($colType === 'date' || $colType === 'datetime') {
                        $castedValue = parse_import_date($rawVal, $colType);
                        if ($castedValue === null && !$nullable) {
                            $castedValue = $colType === 'date' ? '1000-01-01' : '1000-01-01 00:00:00';
                        }
                    } else {
                        // String / Text columns
                        $trimmed = trim($rawVal);
                        if ($trimmed === '') {
                            $castedValue = $nullable ? null : '';
                        } else {
                            $castedValue = $trimmed;
                        }
                    }

                    $insertData[$dbCol] = $castedValue;
                }

                // If we have mapped columns, attempt insert
                if (!empty($insertData)) {
                    try {
                        $db->insert($table, $insertData);
                        $imported++;
                    } catch (Exception $e) {
                        $failed++;
                        $errors[] = "Insert failed: " . $e->getMessage();
                    }
                } else {
                    $failed++;
                    $errors[] = "No mapped column values to insert for row";
                }
            }

            fclose($handle);
            $db->commit();

            // Log import to audit trail
            $audit = new NuAudit();
            $audit->log('import', $table, 0, null, [
                'imported_rows' => $imported,
                'failed_rows'   => $failed
            ]);

            echo json_encode([
                'success'  => true,
                'imported' => $imported,
                'failed'   => $failed,
                'errors'   => array_slice($errors, 0, 50), // return first 50 errors
            ]);

        } catch (Exception $e) {
            if ($db->getPdo()->inTransaction()) {
                $db->rollback();
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    }
}
