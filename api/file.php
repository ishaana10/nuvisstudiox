<?php
declare(strict_types=1);
/** Serve an uploaded file by id (or stored name), from local disk or, for cloud-only files, from the provider. */
require_once dirname(__DIR__) . '/core/module_bootstrap.php';
require_once dirname(__DIR__) . '/core/FileStore.php';

if (!$auth->hasPermission('files.view') && !$auth->hasPermission('documents.view')) { http_response_code(403); exit('Access denied'); }
NuFileStore::ensureSchema();
$db = NuDatabase::getInstance();
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) $f = $db->fetchOne('SELECT * FROM nu_files WHERE file_id = :i', [':i' => $id]);
else { $n = basename((string)($_GET['name'] ?? '')); $f = $n !== '' ? $db->fetchOne('SELECT * FROM nu_files WHERE file_name = :n', [':n' => $n]) : null; }
if (!$f) { http_response_code(404); exit('File not found'); }
$dl = !empty($_GET['dl']);
if ($dl) { try { (new NuAudit())->event('file_download', ($f['file_original_name'] ?? $f['file_name']) . ' downloaded', [], 'success', ['table' => 'nu_files', 'record_id' => $f['file_id']]); } catch (Throwable $t) {} }
NuFileStore::stream($f, $dl);
