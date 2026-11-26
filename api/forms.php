<?php
/**
 * api/forms.php
 * CRUD for nu_forms builder records.
 * Actions: get, save, list, delete, get_by_code, patch_layout
 */
header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/ProjectContext.php';
require_once __DIR__ . '/_form_layout_helpers.php';
$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':          actionGet($db);         break;
    case 'save':         actionSave($db);         break;
    case 'list':         actionList($db);         break;
    case 'delete':       actionDelete($db);       break;
    case 'get_by_code':  actionGetByCode($db);    break;
    case 'patch_layout': actionPatchLayout($db);  break;
    case 'get_fields':   actionGetFields($db);    break;
    case 'get_versions': actionGetVersions($db);  break;
    case 'rollback':     actionRollback($db);     break;
    case 'create_fk_field': actionCreateFkField($db); break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
}

error_log('[FORMS SAVE] POST: ' . json_encode($_POST));
error_log('[FORMS SAVE] INPUT: ' . file_get_contents('php://input'));
// ── Helpers ───────────────────────────────────────────────────────────────

function nu_is_structural_type(string $type): bool {
    return in_array($type, [
        'html','content','button','subform','fieldset',
        'divider','heading','section','group','row','tab'
    ], true);
}

function nu_col_def_for_type(string $type): string {
    switch ($type) {
        case 'number':   return 'DECIMAL(20,6) NULL DEFAULT NULL';
        case 'checkbox': return 'TINYINT(1) NOT NULL DEFAULT 0';
        case 'date':     return 'DATE NULL DEFAULT NULL';
        case 'time':     return 'TIME NULL DEFAULT NULL';
        case 'datetime': return 'DATETIME NULL DEFAULT NULL';
        case 'textarea': return 'TEXT NULL DEFAULT NULL';
        case 'uuid':     return 'VARCHAR(36) NULL DEFAULT NULL';
        default:         return 'VARCHAR(255) NULL DEFAULT NULL';
    }
}

function nu_flatten_fields(array $layout): array {

   return nu_flatten_layout_fields($layout);
}

function nu_resolve_col_name(array $field): string {
    $name = trim($field['name'] ?? $field['fieldname'] ?? '');
    $type = $field['type'] ?? $field['fieldtype'] ?? 'text';
    if ($type === 'lookup') {
        $lk  = $field['lookup'] ?? [];
        $col = trim($lk['store_field'] ?? $lk['storefield'] ?? $lk['store_col'] ?? $lk['storeCol'] ?? $lk['id_column'] ?? $lk['idcolumn'] ?? '');
        if ($col !== '') return preg_replace('/[^a-zA-Z0-9_]/', '', $col);
        if ($name !== '' && !preg_match('/^lookup_\d+$/', $name)) return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        return '';
    }
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}

/**
 * Execute a DDL statement via PDO::exec() — NOT via a prepared statement.
 * MySQL cannot run CREATE/ALTER/DROP TABLE through native prepared statements.
 * All DDL in this file must go through this function.
 */
function nu_ddl(NuDatabase $db, string $sql): void {
    error_log('[forms.php DDL] ' . $sql);
    try {
        $db->exec($sql);
        error_log('[forms.php DDL] OK');
    } catch (Throwable $e) {
        error_log('[forms.php DDL] FAILED: ' . $e->getMessage());
        throw $e;
    }
}

function nu_sync_table_from_layout(NuDatabase $db, string $table, string $layoutJson, string $pkType = 'autoincrement'): void {
    if ($table === '') return;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return;

   $layout = is_string($layoutJson) ? json_decode($layoutJson, true) : $layoutJson;
    if (!is_array($layout)) $layout = [];

  /*  $fields = nu_flatten_layout_fields($layout);
    if (!is_array($layout)) {
        error_log('[forms.php] nu_sync_table_from_layout: invalid JSON for table=' . $table);
        return;
    }*/

     $fields = nu_flatten_fields($layout);
    $desired = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? $field['fieldtype'] ?? 'text';
        if (nu_is_structural_type($type)) continue;
        $col = nu_resolve_col_name($field);
        if ($col === '') continue;
        $desired[$col] = nu_col_def_for_type($type);
    }

    $driver = $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $tableExists = (bool)$db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
    } else {
        $tableExists = (bool)$db->fetchOne("SHOW TABLES LIKE ?", [$table]);
    }
    error_log('[forms.php] nu_sync_table_from_layout: table=' . $table . ' exists=' . ($tableExists ? 'yes' : 'no') . ' desired_cols=' . count($desired));

    if (!$tableExists) {
        // ── CREATE TABLE ──────────────────────────────────────────────────
        if ($driver === 'sqlite') {
            $pkDef = ($pkType === 'uuid')
                ? "`id` TEXT NOT NULL PRIMARY KEY"
                : "`id` INTEGER PRIMARY KEY AUTOINCREMENT";
        } else {
            $pkDef = ($pkType === 'uuid')
                ? "`id` VARCHAR(36) NOT NULL PRIMARY KEY"
                : "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";
        }

        $colsSql = '';
        foreach ($desired as $col => $def) {
            $colsSql .= ",\n  `{$col}` {$def}";
        }
        $colsSql .= ",\n  `user_id` VARCHAR(36) NULL DEFAULT NULL";
        $colsSql .= ",\n  `location` VARCHAR(255) NULL DEFAULT NULL";
        $colsSql .= ",\n  `created_at` DATETIME NULL DEFAULT NULL";
        $colsSql .= ",\n  `updated_at` DATETIME NULL DEFAULT NULL";

        $engineClause = ($driver === 'sqlite') ? "" : " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $createSql = "CREATE TABLE `{$table}` (\n  {$pkDef}{$colsSql}\n)" . $engineClause;
        nu_ddl($db, $createSql);  // uses exec(), not query()
        return;
    }

    // ── ALTER TABLE: add any missing columns ──────────────────────────────
    $existing = [];
    if ($driver === 'sqlite') {
        foreach ($db->fetchAll("PRAGMA table_info(`{$table}`)") as $row) {
            $existing[$row['name']] = true;
        }
    } else {
        foreach ($db->fetchAll("DESCRIBE `{$table}`") as $row) {
            $existing[$row['Field']] = true;
        }
    }

    // Ensure system columns are present in $desired so they are added if missing
    $desired['user_id'] = 'VARCHAR(36) NULL DEFAULT NULL';
    $desired['location'] = 'VARCHAR(255) NULL DEFAULT NULL';
    $desired['created_at'] = 'DATETIME NULL DEFAULT NULL';
    $desired['updated_at'] = 'DATETIME NULL DEFAULT NULL';

    foreach ($desired as $col => $def) {
        if (isset($existing[$col])) continue;
        try {
            // MySQL uses AFTER, not BEFORE. If it is a custom field, find the first existing system column
            // and position this column right before it (by placing it AFTER the column before that system column).
            $positionClause = '';
            if ($driver !== 'sqlite') {
                $systemCols = ['user_id', 'location', 'created_at', 'updated_at'];
                if (!in_array($col, $systemCols, true)) {
                    $firstSystemCol = null;
                    $cols = array_keys($existing);
                    foreach ($cols as $cName) {
                        if (in_array($cName, $systemCols, true)) {
                            $firstSystemCol = $cName;
                            break;
                        }
                    }
                    if ($firstSystemCol !== null) {
                        $caIdx = array_search($firstSystemCol, $cols);
                        if ($caIdx === 0) {
                            $positionClause = ' FIRST';
                        } elseif ($caIdx !== false) {
                            $prevCol = $cols[$caIdx - 1];
                            $positionClause = ' AFTER `' . $prevCol . '`';
                        }
                    }
                }
            }
            nu_ddl($db, "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}{$positionClause}");
            $existing[$col] = true; // keep $existing in sync for next iteration
        } catch (Throwable $e) {
            // logged inside nu_ddl — continue with remaining columns
        }
    }
}

/**
 * Ensure nu_forms has all expected columns (auto-migration).
 */
function nu_ensure_nu_forms_columns(NuDatabase $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $existing = [];
    $driver = $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        foreach ($db->fetchAll("PRAGMA table_info(`nu_forms`)") as $col) {
            $existing[$col['name']] = true;
        }
    } else {
        foreach ($db->fetchAll("DESCRIBE `nu_forms`") as $col) {
            $existing[$col['Field']] = true;
        }
    }

    $needed = [
        'project_id'            => "INT UNSIGNED NOT NULL DEFAULT 1",
        'form_panel_mode'       => "VARCHAR(20) NOT NULL DEFAULT 'fixed'",
        'form_panel_width'      => "INT NOT NULL DEFAULT 0",
        'form_custom_js'        => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_js_before_save'   => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_js_after_save'    => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_custom_php'       => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_custom_php_after' => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_custom_css'       => "MEDIUMTEXT NULL DEFAULT NULL",
        'browse_conditions'     => "JSON NULL DEFAULT NULL",
        'browse_layout'         => "MEDIUMTEXT NULL DEFAULT NULL",
        'browse_delete_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
    ];

    foreach ($needed as $col => $def) {
        if (isset($existing[$col])) continue;
        try {
            nu_ddl($db, "ALTER TABLE `nu_forms` ADD COLUMN `{$col}` {$def}");
        } catch (Throwable $e) {
            // logged inside nu_ddl
        }
    }

    // Ensure nu_form_versions table exists
    try {
        nu_ddl($db, "CREATE TABLE IF NOT EXISTS `nu_form_versions` (
            `ver_id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT UNSIGNED NOT NULL DEFAULT 1,
            `ver_form_id` INT NOT NULL,
            `ver_form_code` VARCHAR(50) NOT NULL,
            `ver_layout` LONGTEXT NULL,
            `ver_settings` LONGTEXT NULL,
            `ver_created_by` VARCHAR(100) NULL,
            `ver_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_ver_form_id` (`ver_form_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // logged inside nu_ddl
    }
}

/**
 * Drop a data table — uses exec() (DDL path).
 */
function nu_drop_table(NuDatabase $db, string $table): array {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return [false, 'Empty table name after sanitisation'];

    $driver = $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $exists = $db->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
    } else {
        $exists = $db->fetchOne("SHOW TABLES LIKE ?", [$table]);
    }
    if (!$exists) return [true, ''];

    try {
        nu_ddl($db, "DROP TABLE `{$table}`");
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

// ── GET a single form by ID ───────────────────────────────────────────────
function actionGet($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    try {
        $pid = ProjectContext::getId();
        $form = $db->fetchOne('SELECT * FROM nu_forms WHERE form_id = ? AND project_id = ? LIMIT 1', [$id, $pid]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found or access denied']); return; }
        echo json_encode(['success' => true, 'form' => $form]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function actionCreateFkField($db) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON payload'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $childFormCode = trim((string)($data['child_form_code'] ?? ''));
        $fkToFormCode  = trim((string)($data['fk_to_form_code'] ?? ''));

        if ($childFormCode === '' || $fkToFormCode === '') {
            echo json_encode(['success' => false, 'error' => 'child_form_code and fk_to_form_code are required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 1. Fetch child form
        $childForm = $db->fetchOne('SELECT * FROM nu_forms WHERE form_code = ?', [$childFormCode]);
        if (!$childForm) {
            echo json_encode(['success' => false, 'error' => "Child form '{$childFormCode}' not found"], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. Fetch parent form to determine table name & PK type
        $parentForm = $db->fetchOne('SELECT * FROM nu_forms WHERE form_code = ?', [$fkToFormCode]);
        if (!$parentForm) {
            echo json_encode(['success' => false, 'error' => "Parent form '{$fkToFormCode}' not found"], JSON_UNESCAPED_UNICODE);
            return;
        }

        $parentTable  = trim((string)($parentForm['form_table'] ?? ''));
        $parentPkType = trim((string)($parentForm['form_pk_type'] ?? 'autoincrement'));

        if ($parentTable === '') {
            $parentTable = preg_replace('/[^a-zA-Z0-9_]/', '', $fkToFormCode);
        }

        // Generate sanitized FK name and label
        $fkName  = $parentTable . '_id';
        $fkLabel = trim(ucwords(str_replace('_', ' ', $parentTable))) . ' ID';

        // 3. Update child form's layout if the field does not already exist
        $childLayoutJson = $childForm['form_layout'] ?? '[]';
        $childLayout = json_decode($childLayoutJson, true);
        if (!is_array($childLayout)) $childLayout = [];

        // Check if field already exists in any nested row
        $flatFields = nu_flatten_fields($childLayout);
        $exists = false;
        foreach ($flatFields as $f) {
            $colName = nu_resolve_col_name($f);
            if (strtolower($colName) === strtolower($fkName)) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            // Append a new row containing the FK field to the child form layout
            $newFkField = [
                'name' => $fkName,
                'label' => $fkLabel,
                'type' => ($parentPkType === 'uuid') ? 'text' : 'number',
                'required' => true,
                'col' => 6,
                'is_fk' => true,
                'hide_in_grid' => true
            ];
            $childLayout[] = [
                'type' => 'row',
                'fields' => [$newFkField]
            ];

            // Update child form layout in the database
            $updateData = [
                'form_layout' => json_encode($childLayout, JSON_UNESCAPED_UNICODE),
            ];
            // Only update form_updated_at if the column exists
            $columns = [];
            if ($driver === 'sqlite') {
                foreach ($db->fetchAll("PRAGMA table_info(`nu_forms`)") as $c) {
                    $columns[] = strtolower($c['name']);
                }
            } else {
                foreach ($db->fetchAll("DESCRIBE `nu_forms`") as $c) {
                    $columns[] = strtolower($c['Field']);
                }
            }
            if (in_array('form_updated_at', $columns, true)) {
                $updateData['form_updated_at'] = date('Y-m-d H:i:s');
            }

            $db->update('nu_forms', $updateData, 'form_id = ?', [$childForm['form_id']]);
        }

        // 4. Synchronize/alter the child table to add the column
        $childTable  = trim((string)($childForm['form_table'] ?? ''));
        $childPkType = trim((string)($childForm['form_pk_type'] ?? 'autoincrement'));
        if ($childTable !== '') {
            nu_sync_table_from_layout($db, $childTable, json_encode($childLayout, JSON_UNESCAPED_UNICODE), $childPkType);
        }

        echo json_encode([
            'success' => true,
            'field_name' => $fkName
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

// ── GET versions for a form ───────────────────────────────────────────────
function actionGetVersions($db) {
    $formId = $_GET['form_id'] ?? '';
    if (!$formId) { echo json_encode(['success' => false, 'error' => 'Missing form_id']); return; }
    try {
        // The form must belong to the active project before its history is revealed.
        $owned = $db->fetchOne('SELECT form_id FROM nu_forms WHERE form_id = ? AND project_id = ?', [$formId, ProjectContext::getId()]);
        if (!$owned) { echo json_encode(['success' => false, 'error' => 'Form not found']); return; }
        $versions = $db->fetchAll('SELECT ver_id, ver_form_id, ver_created_by, ver_created_at FROM nu_form_versions WHERE ver_form_id = ? ORDER BY ver_id DESC LIMIT 50', [$formId]);
        echo json_encode(['success' => true, 'versions' => $versions]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── ROLLBACK form to a previous version ────────────────────────────────────
function actionRollback($db) {
    $verId = $_GET['ver_id'] ?? '';
    if (!$verId) { echo json_encode(['success' => false, 'error' => 'Missing ver_id']); return; }
    try {
        $ver = $db->fetchOne('SELECT * FROM nu_form_versions WHERE ver_id = ?', [$verId]);
        if (!$ver) { echo json_encode(['success' => false, 'error' => 'Version not found']); return; }

        $formId = $ver['ver_form_id'];
        $pid = ProjectContext::getId();
        $form = $db->fetchOne('SELECT * FROM nu_forms WHERE form_id = ? AND project_id = ?', [$formId, $pid]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); return; }

        $settings = json_decode($ver['ver_settings'], true) ?: [];

        $update = [
            'form_layout'               => $ver['ver_layout'],
            'form_updated_at'           => date('Y-m-d H:i:s'),
            'browse_sql'                => (string)($settings['browse_sql'] ?? ''),
            'browse_columns'            => (string)($settings['browse_columns'] ?? ''),
            'browse_layout'             => (string)($settings['browse_layout'] ?? ''),
            'browse_search_enabled'     => isset($settings['browse_search_enabled']) ? (int)$settings['browse_search_enabled'] : 0,
            'browse_search_placeholder' => (string)($settings['browse_search_placeholder'] ?? ''),
            'browse_search_fields'      => (string)($settings['browse_search_fields'] ?? ''),
            'browse_page_size'          => isset($settings['browse_page_size']) ? (int)$settings['browse_page_size'] : 20,
            'browse_default_sort'       => (string)($settings['browse_default_sort'] ?? ''),
            'browse_display_mode'       => (string)($settings['browse_display_mode'] ?? 'inline'),
            'browse_conditions'         => isset($settings['browse_conditions']) ? (is_array($settings['browse_conditions']) ? json_encode($settings['browse_conditions'], JSON_UNESCAPED_UNICODE) : (is_string($settings['browse_conditions']) ? $settings['browse_conditions'] : null)) : null,
            'browse_php'                => (string)($settings['browse_php'] ?? ''),
            'form_custom_js'            => (string)($settings['form_custom_js'] ?? ''),
            'form_js_before_save'       => (string)($settings['form_js_before_save'] ?? ''),
            'form_js_after_save'        => (string)($settings['form_js_after_save'] ?? ''),
            'form_custom_php'           => (string)($settings['form_custom_php'] ?? ''),
            'form_custom_php_after'     => (string)($settings['form_custom_php_after'] ?? ''),
            'form_custom_css'           => (string)($settings['form_custom_css'] ?? ''),
            'form_panel_mode'           => (string)($settings['form_panel_mode'] ?? 'fixed'),
            'form_panel_width'          => isset($settings['form_panel_width']) ? (int)$settings['form_panel_width'] : 0,
        ];

        $db->update('nu_forms', $update, 'form_id = ? AND project_id = ?', [$formId, $pid]);

        $updatedForm = $db->fetchOne('SELECT * FROM nu_forms WHERE form_id = ? AND project_id = ?', [$formId, $pid]);
        echo json_encode(['success' => true, 'form' => $updatedForm]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getLine() . ': ' . $e->getMessage()]);
    }
}

// ── GET a single form by form_code ────────────────────────────────────────
function actionGetByCode($db) {
    $code = $_GET['code'] ?? '';
    if (!$code) { echo json_encode(['success' => false, 'error' => 'Missing code']); return; }
    try {
        $pid = ProjectContext::getId();
        $form = $db->fetchOne('SELECT * FROM nu_forms WHERE form_code = ? AND project_id = ? LIMIT 1', [$code, $pid]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found or access denied']); return; }
        echo json_encode(['success' => true, 'form' => $form]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── PATCH only the form_layout column ────────────────────────────────────
function actionPatchLayout($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['form_layout'])) {
        echo json_encode(['success' => false, 'error' => 'Missing form_layout in payload']);
        return;
    }
    $layout = $data['form_layout'];
    if (is_array($layout)) $layout = json_encode($layout);
    try {
        $pid = ProjectContext::getId();
        $existing = $db->fetchOne('SELECT form_id FROM nu_forms WHERE form_id = ? AND project_id = ?', [$id, $pid]);
        if (!$existing) { echo json_encode(['success' => false, 'error' => 'Form not found or access denied']); return; }

        $db->update('nu_forms', ['form_layout' => $layout, 'form_updated_at' => date('Y-m-d H:i:s')], 'form_id = ? AND project_id = ?', [$id, $pid]);
        $form = $db->fetchOne('SELECT form_table, form_pk_type FROM nu_forms WHERE form_id = ? AND project_id = ?', [$id, $pid]);
        if ($form && !empty($form['form_table'])) {
            try {
                nu_sync_table_from_layout($db, $form['form_table'], $layout, $form['form_pk_type'] ?? 'autoincrement');
            } catch (Throwable $e) {
                error_log('[forms.php] sync after patch_layout: ' . $e->getMessage());
            }
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── LIST all forms ────────────────────────────────────────────────────────
function actionList($db) {
    try {
        $pid = ProjectContext::getId();
        $forms = $db->fetchAll(
            'SELECT form_id, form_name, form_code, form_table, form_type,
                    form_table_mode, form_pk_type, browse_display_mode,
                    form_created_at AS created_at, form_updated_at AS updated_at
             FROM nu_forms WHERE project_id = ? ORDER BY form_updated_at DESC, form_name ASC',
            [$pid]
        );
        echo json_encode(['success' => true, 'forms' => $forms]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}



// ── SAVE (insert or update) ───────────────────────────────────────────────
function actionSave($db) {
    nu_ensure_nu_forms_columns($db);

    // Always return JSON
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON payload'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $formId   = $data['form_id'] ?? null;
        $formName = trim((string)($data['form_name'] ?? ''));
        $formCode = trim((string)($data['form_code'] ?? ''));

        if ($formName === '') {
            echo json_encode(['success' => false, 'error' => 'form_name is required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($formCode === '') {
            $formCode = preg_replace('/[^a-z0-9]+/', '_', strtolower($formName));
        }

        $formLayout = $data['form_layout'] ?? '';
        if (is_array($formLayout)) {
            $formLayout = json_encode($formLayout, JSON_UNESCAPED_UNICODE);
        } else {
            $formLayout = (string)$formLayout;
        }

        $formTable = trim((string)($data['form_table'] ?? ''));
        $pkType    = trim((string)($data['form_pk_type'] ?? 'autoincrement'));
        $tableMode = trim((string)($data['form_table_mode'] ?? 'new'));

        error_log('[forms.php] actionSave: name=' . $formName . ' table=' . $formTable . ' pk=' . $pkType . ' mode=' . $tableMode);

        // Safe browse_columns normalization
        $browseColumns = $data['browse_columns'] ?? '';

        if (is_array($browseColumns)) {
            $tmp = [];
            foreach ($browseColumns as $v) {
                $v = trim((string)$v);
                if ($v !== '') $tmp[] = $v;
            }
            $browseColumns = implode(',', $tmp);
        } else {
            $browseColumns = trim((string)$browseColumns);
        }

        // Default browse columns from real fields only
        if ($browseColumns === '') {
            $flatFields = nu_flatten_layout_fields($formLayout);
            $defaultBrowseColumns = [];

            foreach ($flatFields as $f) {
                $name = trim((string)($f['name'] ?? ''));
                if ($name !== '') {
                    $defaultBrowseColumns[] = $name;
                }
            }

            $browseColumns = implode(',', $defaultBrowseColumns);
        }

        $row = [
            'form_name'                 => $formName,
            'form_code'                 => $formCode,
            'form_table'                => $formTable,
            'form_type'                 => $data['form_type'] ?? 'main',
            'form_table_mode'           => $tableMode,
            'form_pk_type'              => $pkType,
            'form_layout'               => $formLayout,
            'form_active'               => 1,
            'browse_sql'                => (string)($data['browse_sql'] ?? ''),
            'browse_columns'            => $browseColumns,
            'browse_search_enabled'     => isset($data['browse_search_enabled']) ? (int)$data['browse_search_enabled'] : 0,
            'browse_search_placeholder' => (string)($data['browse_search_placeholder'] ?? ''),
            'browse_search_fields'      => (string)($data['browse_search_fields'] ?? ''),
            'browse_page_size'          => isset($data['browse_page_size']) ? (int)$data['browse_page_size'] : 20,
            'browse_default_sort'       => (string)($data['browse_default_sort'] ?? ''),
            'browse_display_mode'       => (string)($data['browse_display_mode'] ?? 'inline'),
            'browse_conditions'         => isset($data['browse_conditions']) ? (is_array($data['browse_conditions']) ? json_encode($data['browse_conditions'], JSON_UNESCAPED_UNICODE) : $data['browse_conditions']) : null,
            'browse_layout'             => (string)($data['browse_layout'] ?? ''),
            'browse_delete_enabled'     => isset($data['browse_delete_enabled']) ? (int)$data['browse_delete_enabled'] : 1,
            'browse_php'                => (string)($data['browse_php'] ?? ''),
            'form_custom_js'            => (string)($data['form_custom_js'] ?? ''),
            'form_js_before_save'       => (string)($data['form_js_before_save'] ?? ''),
            'form_js_after_save'        => (string)($data['form_js_after_save'] ?? ''),
            'form_custom_php'           => (string)($data['form_custom_php'] ?? ''),
            'form_custom_php_after'     => (string)($data['form_custom_php_after'] ?? ''),
            'form_custom_css'           => (string)($data['form_custom_css'] ?? ''),
            'form_panel_mode'           => (string)($data['form_panel_mode'] ?? 'fixed'),
            'form_panel_width'          => isset($data['form_panel_width']) ? (int)$data['form_panel_width'] : 0,
        ];

        $pid = ProjectContext::getId();
        $row['project_id'] = $pid;

        if (!empty($row['form_table'])) {
            ProjectContext::registerProjectTable($pid, $row['form_table'], $formCode);
        }

        if ($formId) {
            $existing = $db->fetchOne('SELECT form_id FROM nu_forms WHERE form_id = ? AND project_id = ?', [$formId, $pid]);
            if (!$existing) {
                echo json_encode(['success' => false, 'error' => 'Form not found or access denied in this project'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $row['form_updated_at'] = date('Y-m-d H:i:s');
            $db->update('nu_forms', $row, 'form_id = ? AND project_id = ?', [$formId, $pid]);
            $savedId = $formId;
            error_log('[forms.php] actionSave: updated form_id=' . $savedId);
        } else {
            $existing = $db->fetchOne('SELECT form_id FROM nu_forms WHERE form_code = ? AND project_id = ?', [$formCode, $pid]);
            if ($existing) {
                echo json_encode(['success' => false, 'error' => "Form code '{$formCode}' already exists in this project"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $row['form_created_at'] = date('Y-m-d H:i:s');
            $row['form_updated_at'] = date('Y-m-d H:i:s');
            $db->insert('nu_forms', $row);
            $savedId = $db->lastInsertId();
            error_log('[forms.php] actionSave: inserted form_id=' . $savedId);
        }

        // Save layout snapshot to nu_form_versions
        try {
            $verSettings = [
                'browse_sql'                => $row['browse_sql'] ?? '',
                'browse_columns'            => $row['browse_columns'] ?? '',
                'browse_layout'             => $row['browse_layout'] ?? '',
                'browse_search_enabled'     => $row['browse_search_enabled'] ?? 0,
                'browse_search_placeholder' => $row['browse_search_placeholder'] ?? '',
                'browse_search_fields'      => $row['browse_search_fields'] ?? '',
                'browse_page_size'          => $row['browse_page_size'] ?? 20,
                'browse_default_sort'       => $row['browse_default_sort'] ?? '',
                'browse_display_mode'       => $row['browse_display_mode'] ?? 'inline',
                'browse_conditions'         => $row['browse_conditions'] ?? null,
                'browse_delete_enabled'     => $row['browse_delete_enabled'] ?? 1,
                'browse_php'                => $row['browse_php'] ?? '',
                'form_custom_js'            => $row['form_custom_js'] ?? '',
                'form_js_before_save'       => $row['form_js_before_save'] ?? '',
                'form_js_after_save'        => $row['form_js_after_save'] ?? '',
                'form_custom_php'           => $row['form_custom_php'] ?? '',
                'form_custom_php_after'     => $row['form_custom_php_after'] ?? '',
                'form_custom_css'           => $row['form_custom_css'] ?? '',
                'form_panel_mode'           => $row['form_panel_mode'] ?? 'fixed',
                'form_panel_width'          => $row['form_panel_width'] ?? 0
            ];
            $auth = NuAuth::getInstance();
            $currentUser = $auth->getCurrentUser();
            $username = $currentUser['usr_name'] ?? $currentUser['usr_login'] ?? 'globeadmin';
            $db->insert('nu_form_versions', [
                'ver_form_id'    => $savedId,
                'project_id'     => $pid,
                'ver_form_code'  => $formCode,
                'ver_layout'     => $formLayout,
                'ver_settings'   => json_encode($verSettings, JSON_UNESCAPED_UNICODE),
                'ver_created_by' => $username,
                'ver_created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Throwable $e) {
            error_log('[forms.php] actionSave: failed to insert version revision: ' . $e->getMessage());
        }

        if ($formTable !== '' && $tableMode !== 'existing_no_sync') {
            try {
                nu_sync_table_from_layout($db, $formTable, $formLayout, $pkType);
            } catch (Throwable $e) {
                error_log('[forms.php] DDL sync FAILED: ' . $e->getMessage());
            }
        } elseif ($formTable === '') {
            error_log('[forms.php] actionSave: no form_table — skipping DDL');
        } else {
            error_log('[forms.php] actionSave: table_mode=existing_no_sync — skipping DDL for ' . $formTable);
        }

        // Sync any subforms' tables as well
        try {
            $subforms = nu_flatten_subforms_from_layout($formLayout);
            foreach ($subforms as $sfField) {
                $sf = $sfField['subform'] ?? [];
                $sfCode = trim((string)($sf['form_code'] ?? $sf['formcode'] ?? ''));
                if ($sfCode !== '') {
                    $childForm = $db->fetchOne('SELECT * FROM nu_forms WHERE form_code = ?', [$sfCode]);
                    if ($childForm) {
                        $childTable = trim((string)($childForm['form_table'] ?? ''));
                        $childPkType = trim((string)($childForm['form_pk_type'] ?? 'autoincrement'));
                        $childLayout = $childForm['form_layout'] ?? '';
                        $childTableMode = trim((string)($childForm['form_table_mode'] ?? 'new'));
                        if ($childTable !== '' && $childTableMode !== 'existing_no_sync') {
                            nu_sync_table_from_layout($db, $childTable, $childLayout, $childPkType);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[forms.php] Recursive subform DDL sync FAILED: ' . $e->getMessage());
        }

        echo json_encode([
            'success'        => true,
            'form_id'        => $savedId,
            'browse_columns' => $browseColumns
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        error_log('[forms.php] actionSave EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
// ── DELETE a form ─────────────────────────────────────────────────────────
function actionDelete($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }

    try {
        $pid = ProjectContext::getId();
        $form = $db->fetchOne('SELECT form_table FROM nu_forms WHERE form_id = ? AND project_id = ?', [$id, $pid]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found or access denied']); return; }

        $formTable = preg_replace('/[^a-zA-Z0-9_]/', '', trim($form['form_table'] ?? ''));

        $db->query('DELETE FROM nu_forms WHERE form_id = ? AND project_id = ?', [$id, $pid]);

        $dropWarning = null;
        if ($formTable !== '') {
            [$dropped, $dropErr] = nu_drop_table($db, $formTable);
            if (!$dropped) {
                $dropWarning = $dropErr;
                error_log('[forms.php] DROP TABLE ' . $formTable . ': ' . $dropErr);
            }
        }

        $resp = ['success' => true];
        if ($dropWarning !== null) $resp['drop_warning'] = $dropWarning;
        echo json_encode($resp);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// Add this function at the bottom of forms.php:
function actionGetFields($db) {
    $code = $_GET['code'] ?? '';
    if (!$code) { echo json_encode(['success' => false, 'error' => 'Missing code']); return; }
    try {
        $form = $db->fetchOne('SELECT form_layout FROM nu_forms WHERE form_code = ? LIMIT 1', [$code]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); return; }

        $layout = json_decode($form['form_layout'] ?? '[]', true);
        if (!is_array($layout)) $layout = [];

        $flat   = nu_flatten_fields($layout);
        $fields = [];
        foreach ($flat as $f) {
            $type = $f['type'] ?? $f['fieldtype'] ?? 'text';
            if (nu_is_structural_type($type)) continue;
            $name  = trim($f['name'] ?? $f['fieldname'] ?? '');
            $label = trim($f['label'] ?? $f['fieldlabel'] ?? $name);
            if ($name === '') continue;
            $fields[] = ['name' => $name, 'label' => $label, 'type' => $type];
        }
        echo json_encode(['success' => true, 'fields' => $fields]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
