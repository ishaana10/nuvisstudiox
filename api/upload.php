<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Audit.php';
require_once '../core/FileStore.php';

header('Content-Type: application/json');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!$auth->hasPermission('files.upload')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Helper to extract CSRF token from headers, POST, or GET
$headers = function_exists('getallheaders') ? getallheaders() : [];
$csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['nu_csrf'] ?? $_GET['csrf_token'] ?? '';

// Secure Deletion Action
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    if (!$auth->verifyCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
        exit;
    }

    $db = NuDatabase::getInstance();
    $file = $db->fetch("SELECT * FROM nu_files WHERE file_id = :id", [':id' => $fileId]);
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit;
    }

    // Remove the local copy and (if any) the cloud copy
    $removed = NuFileStore::remove($file);

    // Delete database entry
    $db->delete('nu_files', 'file_id = :id', [':id' => $fileId]);

    $audit = new NuAudit();
    $audit->log('delete', 'nu_files', $fileId);

    echo json_encode(['success' => true, 'warning' => $removed['warning']]);
    exit;
}

// Verify CSRF token for file upload as well
if (!$auth->verifyCsrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file provided']);
    exit;
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $nuConfig['allowedFileTypes'])) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit;
}

if ($file['size'] > $nuConfig['maxUploadSize']) {
    echo json_encode(['success' => false, 'error' => 'File too large']);
    exit;
}

try {
    $saved = NuFileStore::save($file);
    echo json_encode(['success' => true, 'file_id' => $saved['file_id'], 'name' => $saved['name'], 'storage' => $saved['storage'],
                      'cloud_status' => $saved['remote_status'], 'warning' => $saved['warning']]);
} catch (Throwable $e) {
    error_log('[upload] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
}
