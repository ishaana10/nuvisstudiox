<?php
/**
 * api/procedures.php
 * CRUD + run testing for nu_procedures (Custom PHP Functions).
 * Actions: list, get, save, delete, test_run
 */
header('Content-Type: application/json; charset=utf-8');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Audit.php';
require_once '../core/ProjectContext.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Restricted to globeadmin or admin roles
$currentUser = $auth->getCurrentUser();
$role = strtolower((string)($currentUser['usr_role'] ?? ''));
if ($role !== 'globeadmin' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied. Administrators only.']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

// Saving, deleting or test-running a procedure is code execution/authoring: globeadmin only, and off on production by default.
if (in_array($action, ['save', 'delete', 'test_run'], true)) {
    require_once __DIR__ . '/../core/ProcedureGuard.php';
    NuProcedureGuard::enforce($role);
}

switch ($action) {
    case 'list':     actionList($db);        break;
    case 'get':      actionGet($db);         break;
    case 'save':     actionSave($db);        break;
    case 'delete':   actionDelete($db);      break;
    case 'test_run': actionTestRun($db);     break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
}

// ── LIST ──────────────────────────────────────────────────────────────────
function actionList($db) {
    try {
        $pid = ProjectContext::getId();
        $rows = $db->fetchAll(
            'SELECT procedure_id, procedure_code, procedure_name, procedure_description,
                    procedure_active, procedure_created_at, procedure_updated_at
             FROM nu_procedures
             WHERE project_id = ?
             ORDER BY procedure_updated_at DESC',
            [$pid]
        );
        echo json_encode(['success' => true, 'procedures' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── GET single ────────────────────────────────────────────────────────────
function actionGet($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    try {
        $pid = ProjectContext::getId();
        $row = $db->fetchOne('SELECT * FROM nu_procedures WHERE procedure_id = ? AND project_id = ?', [(int)$id, $pid]);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Procedure not found or access denied']); return; }
        echo json_encode(['success' => true, 'procedure' => $row]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── SAVE (insert or update) ───────────────────────────────────────────────
function actionSave($db) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo json_encode(['success' => false, 'error' => 'Invalid JSON']); return; }

    $procId     = !empty($data['procedure_id'])          ? (int)$data['procedure_id']          : null;
    if ($procId === null && !empty($data['id'])) {
        $procId = (int)$data['id'];
    }
    $procName   = trim($data['procedure_name']            ?? '');
    $procCode   = trim($data['procedure_code']            ?? '');
    $procPhp    = trim($data['procedure_php']             ?? '');
    $procDesc   = trim($data['procedure_description']     ?? '');
    $procActive = isset($data['procedure_active'])        ? (int)$data['procedure_active']      : 1;

    if (!$procName) { echo json_encode(['success' => false, 'error' => 'Name is required']); return; }
    if (!$procCode) {
        $procCode = preg_replace('/[^a-z0-9]+/', '_', strtolower($procName));
        $procCode = trim($procCode, '_');
    }
    $procCode = preg_replace('/[^a-z0-9_]/', '', strtolower($procCode));

    if (!$procCode) { echo json_encode(['success' => false, 'error' => 'Invalid or empty Code']); return; }

    $pid = ProjectContext::getId();
    $row = [
        'project_id'            => $pid,
        'procedure_name'        => $procName,
        'procedure_code'        => $procCode,
        'procedure_description' => $procDesc,
        'procedure_php'         => $procPhp,
        'procedure_active'      => $procActive,
    ];

    try {
        if ($procId) {
            $existing = $db->fetchOne('SELECT procedure_id FROM nu_procedures WHERE procedure_id = ? AND project_id = ?', [$procId, $pid]);
            if (!$existing) { echo json_encode(['success' => false, 'error' => 'Procedure not found or access denied']); return; }
            $db->update('nu_procedures', $row, 'procedure_id = ? AND project_id = ?', [$procId, $pid]);
            $audit = new NuAudit();
            $audit->log('update_procedure', 'nu_procedures', $procId, null, $row);
            echo json_encode(['success' => true, 'procedure_id' => $procId]);
        } else {
            $existing = $db->fetchOne('SELECT procedure_id FROM nu_procedures WHERE procedure_code = ? AND project_id = ?', [$procCode, $pid]);
            if ($existing) {
                echo json_encode(['success' => false, 'error' => "Procedure code '{$procCode}' already exists"]); return;
            }
            $db->insert('nu_procedures', $row);
            $newId = (int)$db->lastInsertId();
            $audit = new NuAudit();
            $audit->log('create_procedure', 'nu_procedures', $newId, null, $row);
            echo json_encode(['success' => true, 'procedure_id' => $newId]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── DELETE ────────────────────────────────────────────────────────────────
function actionDelete($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    try {
        $pid = ProjectContext::getId();
        $db->query('DELETE FROM nu_procedures WHERE procedure_id = ? AND project_id = ?', [(int)$id, $pid]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── TEST RUN ──────────────────────────────────────────────────────────────
function actionTestRun($db) {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo json_encode(['success' => false, 'error' => 'Invalid JSON']); return; }

    $phpCode = $data['procedure_php'] ?? '';
    $params  = $data['params'] ?? [];

    // Check if params is a valid array, if passed as string parse it
    if (is_string($params)) {
        $parsed = json_decode($params, true);
        if (is_array($parsed)) {
            $params = $parsed;
        } else {
            $params = [];
        }
    }

    // Execute PHP in sandboxed context
    $output = '';
    $err = '';
    $_proc_params = $params;
    $_proc_db     = $db;
    $_proc_auth   = new NuAuth();
    $_proc_hash   = [];
    $_proc_result = null;

    ob_start();
    try {
        eval('?>' . $phpCode);
        $output = ob_get_clean();
        echo json_encode([
            'success' => true,
            'output'  => $output,
            'data'    => $_proc_result
        ]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage()
        ]);
    }
}
