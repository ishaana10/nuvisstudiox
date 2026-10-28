<?php
declare(strict_types=1);

require_once __DIR__ . '/cloud/Providers.php';
require_once __DIR__ . '/Audit.php';

/**
 * NuFileStore - one place that saves, sends, and removes uploaded files.
 * Local disk is always written first; when a cloud provider is configured the file is then copied to it.
 * A failed cloud copy never loses the upload: the file stays local and is marked "failed" so it can be retried.
 *
 * nu_files.file_storage        local | gdrive | onedrive | dropbox | webdav   (where the remote copy lives)
 * nu_files.file_remote_status  none | synced | failed
 */
final class NuFileStore
{
    private static bool $schemaOk = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaOk) return;
        self::$schemaOk = true;
        try {
            $db = NuDatabase::getInstance(); $pdo = $db->getPdo();
            $sqlite = $db->getDriverName() === 'sqlite';
            $have = $sqlite ? array_column($db->fetchAll('PRAGMA table_info(nu_files)'), 'name') : array_column($db->fetchAll('SHOW COLUMNS FROM nu_files'), 'Field');
            if (!$have) return;
            $add = [
                'file_storage'       => $sqlite ? "TEXT DEFAULT 'local'" : "VARCHAR(20) NOT NULL DEFAULT 'local'",
                'file_remote_id'     => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
                'file_remote_url'    => $sqlite ? 'TEXT' : 'VARCHAR(1000) NULL',
                'file_remote_status' => $sqlite ? "TEXT DEFAULT 'none'" : "VARCHAR(10) NOT NULL DEFAULT 'none'",
                'file_remote_error'  => $sqlite ? 'TEXT' : 'VARCHAR(500) NULL',
                'file_sha256'        => $sqlite ? 'TEXT' : 'CHAR(64) NULL',
            ];
            foreach ($add as $col => $ddl) if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE nu_files ADD COLUMN `{$col}` {$ddl}");
            if (!$sqlite) {   // a cloud-only file has no local path
                $pdo->exec("ALTER TABLE nu_files MODIFY `file_path` VARCHAR(500) NULL");
            }
        } catch (\Throwable $e) {
            error_log('[NuFileStore schema] ' . $e->getMessage());
        }
    }

    private static function audit(string $action, string $summary, array $d = [], string $status = 'success', $id = null): void
    {
        try { (new NuAudit())->event($action, $summary, $d, $status, ['table' => 'nu_files', 'record_id' => $id]); } catch (\Throwable $t) {}
    }

    private static function seg(string $s): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', '_', $s) ?? '', " .\t");
    }

    /** Remote folder path segments for a file uploaded now. */
    public static function remoteSegments(?array $cfg = null): array
    {
        $cfg = $cfg ?? NuCloudConfig::get();
        $segs = array_values(array_filter(explode('/', NuCloudConfig::cleanFolder((string)$cfg['folder']))));
        if (!empty($cfg['by_project']) && class_exists('ProjectContext')) {
            try {
                $r = NuDatabase::getInstance()->fetchOne('SELECT project_code FROM nu_projects WHERE project_id = ?', [ProjectContext::getId()]);
                if (!empty($r['project_code'])) $segs[] = self::seg((string)$r['project_code']);
            } catch (\Throwable $t) {}
        }
        $segs[] = date('Y'); $segs[] = date('m');
        return array_values(array_filter($segs, fn($s) => $s !== ''));
    }

    /** True when uploads should be copied to a connected cloud account. */
    public static function cloudActive(?array $cfg = null): bool
    {
        $cfg = $cfg ?? NuCloudConfig::get();
        return $cfg['provider'] !== 'local' && NuCloudConfig::isConnected($cfg['provider'], $cfg);
    }

    /** Deny direct web access to the upload folder (files are served via api/file.php); logos stay public for the login page. */
    public static function protectDir(string $dir): void
    {
        $f = rtrim($dir, '/') . '/.htaccess';
        if (is_file($f)) return;
        @file_put_contents($f, self::HTACCESS);
    }

    const HTACCESS = "# Uploaded files are served through api/file.php (authenticated). Block direct access.\n<IfModule mod_authz_core.c>\n  Require all denied\n  <FilesMatch \"^logo_[A-Za-z0-9._-]+\\.(png|jpe?g|gif|webp|svg)$\">\n    Require all granted\n  </FilesMatch>\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n  <FilesMatch \"^logo_[A-Za-z0-9._-]+\\.(png|jpe?g|gif|webp|svg)$\">\n    Allow from all\n  </FilesMatch>\n</IfModule>\nOptions -Indexes\n";

    /**
     * Store an uploaded file. $src is moved to the upload folder (move_uploaded_file for real uploads).
     * @param array{name:string,type?:string,size?:int,tmp_name:string} $file
     * @param array $extra additional nu_files columns (file_table, file_record_id, ...)
     * @return array{file_id:int,name:string,path:string,storage:string,remote_status:string,warning:string}
     */
    public static function save(array $file, array $extra = [], ?string $uploadDir = null, bool $allowCloud = true): array
    {
        global $nuConfig;
        self::ensureSchema();
        $dir = rtrim($uploadDir ?? (string)$nuConfig['uploadPath'], '/') . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Upload folder is not writable.');
        self::protectDir($dir);
        $name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$file['name']);
        $dest = $dir . $name;
        $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $dest) : @rename($file['tmp_name'], $dest);
        if (!$moved) throw new RuntimeException('Upload failed');
        @chmod($dest, 0644);

        $db = NuDatabase::getInstance();
        $row = $extra + [
            'file_name' => $name, 'file_original_name' => $file['name'], 'file_mime_type' => $file['type'] ?? '',
            'file_size' => $file['size'] ?? filesize($dest), 'file_path' => $dest, 'file_uploaded_by' => $_SESSION['nu_user_id'] ?? null,
        ];
        $row['file_sha256'] = hash_file('sha256', $dest) ?: null;
        $id = $db->insert('nu_files', $row);
        self::audit('upload', (string)$file['name'] . ' uploaded', ['size' => (int)$row['file_size'], 'sha256' => $row['file_sha256']], 'success', $id);
        $out = ['file_id' => $id, 'name' => $name, 'path' => $dest, 'storage' => 'local', 'remote_status' => 'none', 'warning' => ''];

        if ($allowCloud && self::cloudActive()) {
            $r = self::push($id);
            $out['storage'] = $r['storage']; $out['remote_status'] = $r['status']; $out['warning'] = $r['error'];
        }
        return $out;
    }

    /** Copy one stored file to the active cloud provider. Safe to call again for failed files. */
    public static function push(int $fileId): array
    {
        self::ensureSchema();
        $db = NuDatabase::getInstance();
        $cfg = NuCloudConfig::get();
        $f = $db->fetchOne('SELECT * FROM nu_files WHERE file_id = :id', [':id' => $fileId]);
        if (!$f) return ['status' => 'failed', 'storage' => 'local', 'error' => 'File not found'];
        $prov = NuCloud::provider(null, $cfg);
        if (!$prov || !NuCloudConfig::isConnected($cfg['provider'], $cfg)) return ['status' => 'none', 'storage' => 'local', 'error' => ''];
        $path = (string)$f['file_path'];
        if ($path === '' || !is_file($path)) {
            $db->update('nu_files', ['file_remote_status' => 'failed', 'file_remote_error' => 'Local copy is missing'], 'file_id = :id', [':id' => $fileId]);
            return ['status' => 'failed', 'storage' => (string)($f['file_storage'] ?? 'local'), 'error' => 'Local copy is missing'];
        }
        try {
            $mime = $f['file_mime_type'] ?: 'application/octet-stream';
            $res = $prov->upload($path, self::remoteSegments($cfg), (string)($f['file_original_name'] ?: $f['file_name']), $mime);
            $upd = ['file_storage' => $prov->id(), 'file_remote_id' => $res['id'], 'file_remote_url' => $res['url'] ?: null, 'file_remote_status' => 'synced', 'file_remote_error' => null];
            if ($cfg['mode'] === 'cloud_only') { @unlink($path); $upd['file_path'] = null; }
            $db->update('nu_files', $upd, 'file_id = :id', [':id' => $fileId]);
            self::audit('cloud_upload', "{$f['file_original_name']} uploaded to " . $prov->label(), ['provider' => $prov->id(), 'size' => (int)$f['file_size'], 'mode' => $cfg['mode']], 'success', $fileId);
            return ['status' => 'synced', 'storage' => $prov->id(), 'error' => ''];
        } catch (\Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 480);
            $db->update('nu_files', ['file_remote_status' => 'failed', 'file_remote_error' => $msg], 'file_id = :id', [':id' => $fileId]);
            self::audit('cloud_upload_failed', "{$f['file_original_name']} could not be copied to " . $prov->label(), ['error' => $msg], 'failure', $fileId);
            return ['status' => 'failed', 'storage' => 'local', 'error' => $msg];
        }
    }

    /** Retry every failed (or never-synced) file that still has a local copy. */
    public static function retryFailed(int $limit = 25): array
    {
        self::ensureSchema();
        $rows = NuDatabase::getInstance()->fetchAll("SELECT file_id FROM nu_files WHERE file_remote_status = 'failed' AND file_path IS NOT NULL ORDER BY file_id LIMIT " . max(1, $limit));
        $ok = 0; $fail = 0; $last = '';
        foreach ($rows as $r) { $x = self::push((int)$r['file_id']); if ($x['status'] === 'synced') $ok++; else { $fail++; $last = $x['error']; } }
        return ['synced' => $ok, 'failed' => $fail, 'last_error' => $last];
    }

    /** Also copy files that existed before cloud storage was enabled. */
    public static function backfill(int $limit = 25): array
    {
        self::ensureSchema();
        $rows = NuDatabase::getInstance()->fetchAll("SELECT file_id FROM nu_files WHERE (file_remote_status IS NULL OR file_remote_status = 'none') AND file_path IS NOT NULL ORDER BY file_id LIMIT " . max(1, $limit));
        $ok = 0; $fail = 0; $last = '';
        foreach ($rows as $r) { $x = self::push((int)$r['file_id']); if ($x['status'] === 'synced') $ok++; else { $fail++; $last = $x['error']; } }
        return ['synced' => $ok, 'failed' => $fail, 'last_error' => $last, 'remaining' => (int)(NuDatabase::getInstance()->fetchOne("SELECT COUNT(*) c FROM nu_files WHERE (file_remote_status IS NULL OR file_remote_status = 'none') AND file_path IS NOT NULL")['c'] ?? 0)];
    }

    /** Resolve the local file path of a row (handles the older relative paths). */
    public static function localPath(array $f): ?string
    {
        $p = (string)($f['file_path'] ?? '');
        if ($p === '') return null;
        foreach ([$p, dirname(__DIR__) . '/' . ltrim($p, '/')] as $cand) if (is_file($cand)) return $cand;
        return null;
    }

    /** Send the file to the browser from disk or, if it only lives in the cloud, from the provider. */
    public static function stream(array $f, bool $download): void
    {
        $name = (string)($f['file_original_name'] ?: $f['file_name']);
        $mime = (string)($f['file_mime_type'] ?: 'application/octet-stream');
        // only types a browser can safely render inline; everything else downloads
        $inlineOk = in_array(strtolower($mime), ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
        $disp = ($download || !$inlineOk) ? 'attachment' : 'inline';
        $send = function () use ($mime, $disp, $name) {
            header('Content-Type: ' . ($disp === 'inline' ? $mime : 'application/octet-stream'));
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
            header('Content-Disposition: ' . $disp . '; filename="' . str_replace(['"', "\r", "\n", '\\'], '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('Cache-Control: private, max-age=300');
        };
        $local = self::localPath($f);
        if ($local) {
            $send(); header('Content-Length: ' . filesize($local)); readfile($local);
            return;
        }
        if (($f['file_storage'] ?? 'local') === 'local' || empty($f['file_remote_id'])) { http_response_code(404); echo 'File not found'; return; }
        $prov = NuCloud::provider((string)$f['file_storage']);
        if (!$prov) { http_response_code(502); echo 'Storage provider unavailable'; return; }
        try {
            $started = false;
            $prov->download((string)$f['file_remote_id'], function ($chunk) use (&$started, $send) {
                if (!$started) { $send(); $started = true; }
                echo $chunk; if (ob_get_level()) @ob_flush(); flush();
            });
            if (!$started) $send();
            self::audit('cloud_download', "{$name} fetched from " . $prov->label(), ['provider' => $prov->id()], 'success', $f['file_id'] ?? null);
        } catch (\Throwable $e) {
            self::audit('cloud_download_failed', "{$name} could not be fetched from " . $prov->label(), ['error' => $e->getMessage()], 'failure', $f['file_id'] ?? null);
            if (!headers_sent()) { http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); }
            echo 'Could not retrieve the file from cloud storage: ' . $e->getMessage();
        }
    }

    /** Delete local and remote copies. Remote failure is reported but does not block removing the record. */
    public static function remove(array $f): array
    {
        $warn = '';
        if (!empty($f['file_remote_id']) && ($f['file_storage'] ?? 'local') !== 'local') {
            try {
                $prov = NuCloud::provider((string)$f['file_storage']);
                if ($prov) $prov->delete((string)$f['file_remote_id']);
                self::audit('cloud_delete', ($f['file_original_name'] ?? '') . ' removed from ' . ($prov ? $prov->label() : $f['file_storage']), [], 'success', $f['file_id'] ?? null);
            } catch (\Throwable $e) {
                $warn = $e->getMessage();
                self::audit('cloud_delete_failed', ($f['file_original_name'] ?? '') . ' could not be removed from the cloud', ['error' => $warn], 'failure', $f['file_id'] ?? null);
            }
        }
        $local = self::localPath($f);
        if ($local) @unlink($local);
        return ['warning' => $warn];
    }
}
