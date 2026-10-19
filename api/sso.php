<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Sso/SsoProviderInterface.php';
require_once __DIR__ . '/../core/Sso/SamlProvider.php';
require_once __DIR__ . '/../core/Sso/OidcProvider.php';
require_once __DIR__ . '/../core/Sso/SsoManager.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

header('Content-Type: application/json');

$db     = NuDatabase::getInstance();
$auth   = NuAuth::getInstance();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sso_login':
        $providerId = $_GET['provider'] ?? 'default';
        $provider   = \Nuvis\Sso\SsoManager::getProvider($providerId);
        if (!$provider) {
            header('Location: ../index.php?sso_error=' . urlencode('SSO provider not found or disabled.'));
            exit;
        }
        $url = $provider->getLoginUrl();
        header('Location: ' . $url);
        exit;

    case 'sso_callback':
        $providerId = $_GET['provider'] ?? 'default';
        $provider   = \Nuvis\Sso\SsoManager::getProvider($providerId);
        if (!$provider) {
            header('Location: ../index.php?sso_error=' . urlencode('SSO provider not found.'));
            exit;
        }

        $result = $provider->handleCallback();
        if (empty($result['success'])) {
            header('Location: ../index.php?sso_error=' . urlencode($result['error'] ?? 'SSO authentication failed.'));
            exit;
        }

        $user = \Nuvis\Sso\SsoManager::findOrCreateFromSso($result['attributes'] ?? []);
        if (!$user) {
            header('Location: ../index.php?sso_error=' . urlencode('Unable to match or provision SSO user account.'));
            exit;
        }

        // Complete SSO login
        $auth->loginViaSso($user);

        header('Location: ../index.php');
        exit;

    case 'get_providers':
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        $user = $auth->getCurrentUser();
        if (!$user || ($user['usr_role'] !== 'globeadmin' && !$auth->hasPermission('system.config'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
        $providers = $db->fetchAll("SELECT * FROM nu_sso_providers ORDER BY id ASC") ?: [];
        echo json_encode(['success' => true, 'providers' => $providers]);
        break;

    case 'save_provider':
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        $user = $auth->getCurrentUser();
        if (!$user || ($user['usr_role'] !== 'globeadmin' && !$auth->hasPermission('system.config'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id     = (int)($input['id'] ?? 0);
        $name   = trim((string)($input['name'] ?? ''));
        $type   = trim((string)($input['type'] ?? 'oidc'));
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $isDefault = !empty($input['is_default']) ? 1 : 0;
        $config = is_array($input['config'] ?? null) ? json_encode($input['config']) : (string)($input['config_json'] ?? '{}');

        if ($name === '') {
            echo json_encode(['success' => false, 'error' => 'Provider name is required.']);
            break;
        }

        if ($id > 0) {
            $db->update('nu_sso_providers', [
                'name'        => $name,
                'type'        => $type,
                'enabled'     => $enabled,
                'is_default'  => $isDefault,
                'config_json' => $config,
            ], "id = :id", [':id' => $id]);
        } else {
            $id = $db->insert('nu_sso_providers', [
                'name'        => $name,
                'type'        => $type,
                'enabled'     => $enabled,
                'is_default'  => $isDefault,
                'config_json' => $config,
            ]);
        }

        echo json_encode(['success' => true, 'id' => $id, 'message' => 'SSO provider saved.']);
        break;

    case 'delete_provider':
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        $user = $auth->getCurrentUser();
        if (!$user || ($user['usr_role'] !== 'globeadmin' && !$auth->hasPermission('system.config'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }

        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $db->delete('nu_sso_providers', "id = :id", [':id' => $id]);
        }
        echo json_encode(['success' => true, 'message' => 'SSO provider deleted.']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
