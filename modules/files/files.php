<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

if (!$auth->hasPermission('files.view')) {
    http_response_code(403);
    exit('Access denied');
}

$db    = NuDatabase::getInstance();
require_once dirname(__DIR__, 2) . '/core/FileStore.php';
NuFileStore::ensureSchema();
$files = $db->fetchAll("SELECT * FROM nu_files ORDER BY file_uploaded_at DESC LIMIT 50");
$cloudLabels = NuCloud::labels();

// Parse global allowed file extensions and max upload size
$allowedTypes = array_map(function($ext) {
    return '.' . ltrim($ext, '.');
}, $nuConfig['allowedFileTypes'] ?? []);
$maxSize = $nuConfig['maxUploadSize'] ?? 10 * 1024 * 1024;
?>

<div class="nu-files">
    <div class="nu-card">
        <div class="nu-card-header" style="flex-direction:column; align-items:stretch; gap:12px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 class="nu-card-title">File Manager</h3>
            </div>

            <?php if ($auth->hasPermission('files.upload')): ?>
            <div id="uppy-dashboard" style="max-width: 100%; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; background: var(--bg-offset);"></div>
            <?php endif; ?>
        </div>

        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>File</th><th>Type</th><th>Size</th><th>Stored</th><th>Uploaded</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $f): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($f['file_original_name'] ?? $f['file_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($f['file_mime_type'] ?? '-'); ?></td>
                        <td><?php echo number_format($f['file_size'] / 1024, 1); ?> KB</td>
                        <td>
                            <?php $stg = $f['file_storage'] ?? 'local'; $rs = $f['file_remote_status'] ?? 'none'; ?>
                            <?php if ($rs === 'synced'): ?><span title="<?php echo htmlspecialchars($cloudLabels[$stg] ?? $stg); ?>">☁ <?php echo htmlspecialchars(explode(' ', $cloudLabels[$stg] ?? $stg)[0]); ?></span>
                            <?php elseif ($rs === 'failed'): ?><span style="color:#b91c1c" title="<?php echo htmlspecialchars((string)($f['file_remote_error'] ?? '')); ?>">⚠ Cloud copy failed</span>
                            <?php else: ?><span style="color:var(--text-muted)">This server</span><?php endif; ?>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($f['file_uploaded_at'])); ?></td>
                        <td>
                            <a href="api/file.php?id=<?php echo (int)$f['file_id']; ?>" target="_blank" class="nu-btn nu-btn-ghost nu-btn-sm">View</a>
                            <a href="api/file.php?id=<?php echo (int)$f['file_id']; ?>&dl=1" class="nu-btn nu-btn-ghost nu-btn-sm">Download</a>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteFile(<?php echo $f['file_id']; ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($files)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);">No files uploaded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function() {
    const dashboardEl = document.getElementById('uppy-dashboard');
    if (!dashboardEl) return;

    const allowedTypes = <?php echo json_encode($allowedTypes); ?>;
    const maxFileSize = <?php echo json_encode($maxSize); ?>;

    const uppy = new Uppy.Uppy({
        autoProceed: false,
        restrictions: {
            maxFileSize: maxFileSize,
            allowedFileTypes: allowedTypes
        }
    })
    .use(Uppy.Dashboard, {
        target: '#uppy-dashboard',
        inline: true,
        height: 260,
        width: '100%',
        showProgressDetails: true,
        proudlyDisplayPoweredByUppy: false
    })
    .use(Uppy.XHRUpload, {
        endpoint: 'api/upload.php',
        fieldName: 'file',
        formData: true
    });

    uppy.on('complete', (result) => {
        if (result.successful.length > 0) {
            NuApp.toast('Files uploaded successfully!', 'success');
            setTimeout(() => {
                uppy.close();
                NuApp.loadModule('files');
            }, 800);
        }
    });
})();

window.deleteFile = async function(id) {
    if (!confirm('Are you sure you want to delete this file permanently?')) return;
    try {
        const res = await fetch('api/upload.php?action=delete&id=' + id, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': window.nuCsrfToken || ''
            }
        });
        const json = await res.json();
        if (json.success) {
            NuApp.toast('File deleted successfully', 'success');
            NuApp.loadModule('files');
        } else {
            NuApp.toast(json.error || 'Failed to delete file', 'error');
        }
    } catch (e) {
        NuApp.toast('Error deleting file: ' + e.message, 'error');
    }
};
</script>
