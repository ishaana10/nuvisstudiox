<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Audit.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));
require_once '../core/ProcedureGuard.php';
NuProcedureGuard::enforce($role);

$db = NuDatabase::getInstance();
$input = json_decode(file_get_contents('php://input'), true);

$code = $input['code'] ?? '';
$params = $input['params'] ?? [];
$hashCookies = $input['hashCookies'] ?? [];

if (!$code) {
    echo json_encode(['success' => false, 'error' => 'Procedure code required']);
    exit;
}

// Fetch procedure
require_once '../core/ProjectContext.php';
$proc = $db->fetchOne("SELECT * FROM nu_procedures WHERE procedure_code = ? AND procedure_active = 1 AND (project_id = ? OR project_id IS NULL)", [$code, ProjectContext::getId()]);

if (!$proc) {
    echo json_encode(['success' => false, 'error' => 'Procedure not found: ' . $code]);
    exit;
}

// Execute PHP in sandboxed context
$result = null;
$error = null;

try {
    // Record the run before executing: eval'd code may exit or fail without returning here.
    (new NuAudit())->log('run_procedure', 'nu_procedures', (int)($proc['procedure_id'] ?? 0), null, ['code' => $code, 'by' => $_SESSION['nu_user_id'] ?? null]);

    // Create sandbox variables
    $_proc_params = $params;
    $_proc_db = $db;
    $_proc_auth = $auth;
    $_proc_hash = $hashCookies;

    // Use output buffering to catch any echo/print
    ob_start();

    // Execute procedure code
    eval('?>' . $proc['procedure_php']);

    $output = ob_get_clean();

    $result = [
        'success' => true,
        'output' => $output,
        'data' => $_proc_result ?? null
    ];
} catch (Exception $e) {
    $error = $e->getMessage();
    $result = ['success' => false, 'error' => $error];
}

echo json_encode($result);