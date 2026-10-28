<?php
declare(strict_types=1);
/**
 * Serve an uploaded file (local disk, or the cloud provider for cloud-only files).
 *   ?id=N        by file id        needs files.view / documents.view
 *   ?name=STORED by stored name    any signed-in user (names are random, used by form file fields)
 *   &meta=1      JSON description (name, type, size) instead of the bytes
 *   &dl=1        force download (audited)
 * The in-app viewer (assets/js/nu-doc-viewer.js) calls this with fetch(), so the browser never navigates to the file.
 */
require_once dirname(__DIR__) . '/core/module_bootstrap.php';
require_once dirname(__DIR__) . '/core/FileStore.php';

$byName = !isset($_GET['id']) && isset($_GET['name']);
if (($_SESSION['nu_user_id'] ?? '') === 'guest_tester') { http_response_code(403); exit('Access denied'); }
if (!$byName && !$auth->hasPermission('files.view') && !$auth->hasPermission('documents.view')) { http_response_code(403); exit('Access denied'); }

NuFileStore::ensureSchema();
if (!empty($nuConfig['uploadPath']) && is_dir($nuConfig['uploadPath'])) NuFileStore::protectDir($nuConfig['uploadPath']); // keep direct URLs blocked on existing installs
$db = NuDatabase::getInstance();
if (!$byName) { $f = $db->fetchOne('SELECT * FROM nu_files WHERE file_id = :i', [':i' => (int)$_GET['id']]); }
else { $n = basename((string)$_GET['name']); $f = $n !== '' ? $db->fetchOne('SELECT * FROM nu_files WHERE file_name = :n', [':n' => $n]) : null; }
if (!$f && $byName) {
    // Older uploads that sit in the uploads folder without a nu_files record: serve them if the type is allowed.
    $n = basename((string)$_GET['name']);
    $dir = rtrim((string)($nuConfig['uploadPath'] ?? ''), '/') . '/';
    $e = strtolower(pathinfo($n, PATHINFO_EXTENSION));
    if ($n !== '' && $n[0] !== '.' && in_array($e, array_map('strtolower', (array)($nuConfig['allowedFileTypes'] ?? [])), true) && is_file($dir . $n)) {
        $mimes = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'csv' => 'text/csv',
                  'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                  'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        $f = ['file_id' => 0, 'file_name' => $n, 'file_original_name' => preg_replace('/^[0-9a-f]{13}_/', '', $n), 'file_mime_type' => $mimes[$e] ?? 'application/octet-stream',
              'file_size' => filesize($dir . $n), 'file_path' => $dir . $n, 'file_storage' => 'local'];
    }
}
if (!$f) { http_response_code(404); exit('File not found'); }

if (!empty($_GET['meta'])) {
    header('Content-Type: application/json; charset=utf-8');
    $local = NuFileStore::localPath($f);
    echo json_encode(['success' => true, 'id' => (int)$f['file_id'], 'name' => (string)($f['file_original_name'] ?: $f['file_name']),
        'mime' => (string)($f['file_mime_type'] ?? ''), 'size' => (int)($f['file_size'] ?? 0), 'stored' => $local ? 'local' : (string)($f['file_storage'] ?? 'local')]);
    exit;
}

$dl = !empty($_GET['dl']);
if ($dl) { try { (new NuAudit())->event('file_download', ($f['file_original_name'] ?? $f['file_name']) . ' downloaded', [], 'success', ['table' => 'nu_files', 'record_id' => $f['file_id']]); } catch (Throwable $t) {} }
NuFileStore::stream($f, $dl);
