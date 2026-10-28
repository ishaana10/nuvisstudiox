<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db   = NuDatabase::getInstance();
$docs = $db->fetchAll(
    "SELECT d.*, f.file_name, f.file_original_name, f.file_mime_type, u.usr_username
     FROM nu_documents d
     LEFT JOIN nu_files f ON d.doc_file_id = f.file_id
     LEFT JOIN nu_users u ON d.doc_created_by = u.usr_id
     ORDER BY d.doc_created_at DESC"
);
?>

<div class="nu-documents">
    <div class="nu-card" style="margin-bottom: 24px;">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Document Management</h3>
            <button class="nu-btn nu-btn-primary" onclick="openDocModal()">+ New Document</button>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>Title</th><th>Category</th><th>Status</th><th>Created By</th><th>Created</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $d): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($d['doc_title']); ?></strong></td>
                        <td><?php echo htmlspecialchars($d['doc_category'] ?? '-'); ?></td>
                        <td><span class="nu-status nu-status-<?php echo $d['doc_status'] === 'approved' ? 'active' : ($d['doc_status'] === 'rejected' ? 'inactive' : 'pending'); ?>"><?php echo ucfirst($d['doc_status']); ?></span></td>
                        <td><?php echo htmlspecialchars($d['usr_username'] ?? '-'); ?></td>
                        <td><?php echo date('M j, Y', strtotime($d['doc_created_at'])); ?></td>
                        <td>
                            <?php if ($d['file_name']): ?>
                            <a href="api/file.php?id=<?php echo (int)$d['doc_file_id']; ?>" target="_blank" class="nu-btn nu-btn-ghost nu-btn-sm">View</a>
                            <a href="api/file.php?id=<?php echo (int)$d['doc_file_id']; ?>&dl=1" download class="nu-btn nu-btn-ghost nu-btn-sm">Download</a>
                            <?php endif; ?>
                            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="signDocument(<?php echo $d['doc_id']; ?>)">Sign</button>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteDocument(<?php echo $d['doc_id']; ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($docs)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);">No documents. Upload your first document.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="nu-modal-overlay" id="docModal">
    <div class="nu-modal">
        <div class="nu-modal-header">
            <h3 class="nu-modal-title">Upload Document</h3>
            <button class="nu-modal-close" onclick="closeDocModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nu-modal-body">
            <div class="nu-field">
                <label>Title</label>
                <input type="text" class="nu-input" id="docTitle" placeholder="Contract Agreement">
            </div>
            <div class="nu-field">
                <label>Description</label>
                <textarea class="nu-input" id="docDescription" rows="3"></textarea>
            </div>
            <div class="nu-field">
                <label>Category</label>
                <select class="nu-input" id="docCategory">
                    <option value="contract">Contract</option>
                    <option value="invoice">Invoice</option>
                    <option value="report">Report</option>
                    <option value="policy">Policy</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="nu-field">
                <label>Document File</label>
                <input type="file" class="nu-input" id="docFile" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
            </div>
        </div>
        <div class="nu-modal-footer">
            <button class="nu-btn nu-btn-ghost" onclick="closeDocModal()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="saveDocument()">Upload</button>
        </div>
    </div>
</div>

<div class="nu-modal-overlay" id="sigModal">
    <div class="nu-modal" style="max-width:600px;">
        <div class="nu-modal-header">
            <h3 class="nu-modal-title">Digital Signature</h3>
            <button class="nu-modal-close" onclick="closeSigModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nu-modal-body">
            <p style="margin-bottom:12px;font-size:14px;color:var(--text-secondary);">Sign below using your mouse or touch:</p>
            <canvas id="sigCanvas" style="width:100%;height:200px;background:var(--bg-secondary);border:2px solid var(--border-color);border-radius:var(--radius-md);cursor:crosshair;"></canvas>
            <div style="display:flex;gap:8px;margin-top:12px;">
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="clearSignature()">Clear</button>
                <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="saveSignature()">Save Signature</button>
            </div>
        </div>
    </div>
</div>
