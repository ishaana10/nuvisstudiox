<?php
/**
 * REST API for Developer Settings
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';

header('Content-Type: application/json');

$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$currentUser || strtolower((string)$currentUser['usr_role']) !== 'globeadmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden. Restricted to globeadmin.']);
    exit;
}

$db = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_fn_ref':
        try {
            require_once dirname(__DIR__) . '/core/FnRef.php';
            echo json_encode(['success' => true, 'fn_ref' => NuFnRef::getReference()]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to load function reference: ' . $e->getMessage()]);
        }
        break;

    case 'get':
        try {
            $settings = [];
            $rows = $db->fetchAll("SELECT * FROM nu_system_settings");
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            // Parse system_fields_def JSON safely
            if (isset($settings['system_fields_def'])) {
                $settings['system_fields_def'] = json_decode($settings['system_fields_def'], true) ?? [];
            } else {
                $settings['system_fields_def'] = [];
            }
            echo json_encode(['success' => true, 'settings' => $settings]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to load settings: ' . $e->getMessage()]);
        }
        break;

    case 'save':
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
                exit;
            }

            if (isset($input['app_name'])) {
                $db->update('nu_system_settings', ['setting_value' => trim((string)$input['app_name'])], "setting_key = 'app_name'");
            }
            if (isset($input['user_header_format'])) {
                $existing = $db->fetchOne("SELECT setting_key FROM nu_system_settings WHERE setting_key = 'user_header_format'");
                if ($existing) {
                    $db->update('nu_system_settings', ['setting_value' => trim((string)$input['user_header_format'])], "setting_key = 'user_header_format'");
                } else {
                    $db->insert('nu_system_settings', ['setting_key' => 'user_header_format', 'setting_value' => trim((string)$input['user_header_format'])]);
                }
            }
            if (isset($input['app_logo'])) {
                $db->update('nu_system_settings', ['setting_value' => trim((string)$input['app_logo'])], "setting_key = 'app_logo'");
            }
            if (isset($input['forgot_password_enabled'])) {
                // Ensure field exists before updating, or use insert-or-update
                $existing = $db->fetchOne("SELECT setting_key FROM nu_system_settings WHERE setting_key = 'forgot_password_enabled'");
                if ($existing) {
                    $db->update('nu_system_settings', ['setting_value' => trim((string)$input['forgot_password_enabled'])], "setting_key = 'forgot_password_enabled'");
                } else {
                    $db->insert('nu_system_settings', ['setting_key' => 'forgot_password_enabled', 'setting_value' => trim((string)$input['forgot_password_enabled'])]);
                }
            }
            if (isset($input['mfa_enforcement'])) {
                $existing = $db->fetchOne("SELECT setting_key FROM nu_system_settings WHERE setting_key = 'mfa_enforcement'");
                if ($existing) {
                    $db->update('nu_system_settings', ['setting_value' => trim((string)$input['mfa_enforcement'])], "setting_key = 'mfa_enforcement'");
                } else {
                    $db->insert('nu_system_settings', ['setting_key' => 'mfa_enforcement', 'setting_value' => trim((string)$input['mfa_enforcement'])]);
                }
            }
            if (isset($input['system_fields_def'])) {
                $fieldsJson = json_encode($input['system_fields_def']);
                $db->update('nu_system_settings', ['setting_value' => $fieldsJson], "setting_key = 'system_fields_def'");
            }

            // Clear session caching variables to force a reload of settings on next request
            if (session_status() === PHP_SESSION_ACTIVE) {
                unset($_SESSION['_nu_system_settings_ensured']);
            }

            echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to save settings: ' . $e->getMessage()]);
        }
        break;

    case 'upload_logo':
        try {
            if (!isset($_FILES['logo_file'])) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                exit;
            }

            $file = $_FILES['logo_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'File upload error code: ' . $file['error']]);
                exit;
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
            if (!in_array($ext, $allowed, true)) {
                echo json_encode(['success' => false, 'error' => 'Only image files (jpg, jpeg, png, gif, svg, webp) are allowed']);
                exit;
            }

            // Limit upload size to 2MB for logos
            if ($file['size'] > 2097152) {
                echo json_encode(['success' => false, 'error' => 'Logo file must be smaller than 2MB']);
                exit;
            }

            $uploadDir = $nuConfig['uploadPath'] ?? (dirname(__DIR__) . '/uploads/');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            require_once dirname(__DIR__) . '/core/FileStore.php';
            NuFileStore::protectDir($uploadDir);

            $filename = 'logo_' . uniqid() . '.' . $ext;
            $dest = $uploadDir . $filename;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Return web relative path
                $baseUrl = $nuConfig['baseUrl'] ?? '/nbv5u/m/';
                $relativeWebPath = 'uploads/' . $filename;

                // Keep it completely clean by saving to DB
                $db->update('nu_system_settings', ['setting_value' => $relativeWebPath], "setting_key = 'app_logo'");

                if (session_status() === PHP_SESSION_ACTIVE) {
                    unset($_SESSION['_nu_system_settings_ensured']);
                }

                echo json_encode(['success' => true, 'logo_url' => $relativeWebPath]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file']);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Logo upload failed: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
