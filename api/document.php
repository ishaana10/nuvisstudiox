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

$action = $_GET['action'] ?? '';
$db = NuDatabase::getInstance();

/** A document belongs to the project of its file (legacy / file-less documents count as default-project data). */
function nu_doc_allowed(NuDatabase $db, $docId): bool {
    $row = $db->fetchOne('SELECT d.doc_id, f.file_id, f.project_id FROM nu_documents d LEFT JOIN nu_files f ON f.file_id = d.doc_file_id WHERE d.doc_id = :id', [':id' => $docId]);
    if (!$row) return false;
    return NuFileStore::canAccess(['project_id' => $row['project_id'] ?? null]);
}

switch ($action) {
    case 'upload':
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'No file provided']);
            exit;
        }
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type']);
            exit;
        }

        try {
            $saved = NuFileStore::save($file);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Upload failed']);
            exit;
        }
        {
            $fileId = $saved['file_id'];

            $docId = $db->insert('nu_documents', [
                'doc_title' => $_POST['title'] ?? 'Untitled',
                'doc_description' => $_POST['description'] ?? '',
                'doc_file_id' => $fileId,
                'doc_category' => $_POST['category'] ?? 'other',
                'doc_status' => 'draft',
                'doc_created_by' => $_SESSION['nu_user_id']
            ]);

            $audit = new NuAudit();
            $audit->log('document_upload', 'nu_documents', $docId);

            echo json_encode(['success' => true, 'document_id' => $docId, 'file_id' => $fileId, 'warning' => $saved['warning']]);
        }
        break;

    case 'sign':
        $docId = $_GET['id'] ?? 0;
        if (!nu_doc_allowed($db, $docId)) { echo json_encode(['success' => false, 'error' => 'Document not found']); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        $sigData = $data['signature'] ?? '';

        if (!$sigData) {
            echo json_encode(['success' => false, 'error' => 'No signature data']);
            exit;
        }

        // Save signature
        $db->insert('nu_signatures', [
            'sig_document_id' => $docId,
            'sig_user_id' => $_SESSION['nu_user_id'],
            'sig_data' => $sigData,
            'sig_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'sig_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Update document status
        $db->update('nu_documents', ['doc_status' => 'approved'], 'doc_id = :id', [':id' => $docId]);

        $audit = new NuAudit();
        $audit->log('document_sign', 'nu_documents', $docId);

        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $id = $_GET['id'] ?? 0;
        if (!nu_doc_allowed($db, $id)) { echo json_encode(['success' => false, 'error' => 'Document not found']); exit; }
        $db->delete('nu_documents', 'doc_id = :id', [':id' => $id]);
        $audit = new NuAudit();
        $audit->log('document_delete', 'nu_documents', $id);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
?>
