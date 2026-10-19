<?php
declare(strict_types=1);

namespace Nuvis\Sso;

use League\OAuth2\Client\Provider\GenericProvider;

class OidcProvider implements SsoProviderInterface {
    private array $config;
    private array $providerData;

    public function __construct(array $providerData) {
        $this->providerData = $providerData;
        $this->config = is_array($providerData['config_json'] ?? null)
            ? $providerData['config_json']
            : (json_decode($providerData['config_json'] ?? '{}', true) ?: []);
    }

    public function getLoginUrl(): string {
        $provider = $this->buildOAuthProvider();
        $authUrl  = $provider->getAuthorizationUrl(['scope' => $this->config['scopes'] ?? ['openid', 'profile', 'email']]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['oauth2state'] = $provider->getState();
        }
        return $authUrl;
    }

    public function handleCallback(): array {
        if (!empty($_GET['state']) && !empty($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state']) {
            unset($_SESSION['oauth2state']);
            return ['success' => false, 'error' => 'Invalid OAuth state token'];
        }

        $code = $_GET['code'] ?? '';
        if ($code === '') {
            return ['success' => false, 'error' => 'Missing OAuth authorization code'];
        }

        try {
            $provider = $this->buildOAuthProvider();
            $accessToken = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $resourceOwner = $provider->getResourceOwner($accessToken);
            $userArray = $resourceOwner->toArray();

            $mapped = [
                'email'    => $userArray['email'] ?? $userArray['preferred_username'] ?? $userArray['upn'] ?? '',
                'username' => $userArray['preferred_username'] ?? $userArray['email'] ?? $userArray['sub'] ?? '',
                'name'     => $userArray['name'] ?? ($userArray['given_name'] ?? '') . ' ' . ($userArray['family_name'] ?? ''),
                'sso_id'   => $userArray['sub'] ?? $userArray['id'] ?? '',
                'raw'      => $userArray,
            ];

            return ['success' => true, 'attributes' => array_filter($mapped)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'OAuth Error: ' . $e->getMessage()];
        }
    }

    public function getLogoutUrl(?string $nameId = null): ?string {
        return $this->config['end_session_endpoint'] ?? null;
    }

    private function buildOAuthProvider(): GenericProvider {
        $baseUrl = rtrim($GLOBALS['nuConfig']['baseUrl'] ?? 'http://localhost/', '/') . '/';
        return new GenericProvider([
            'clientId'                => $this->config['client_id'] ?? '',
            'clientSecret'            => $this->config['client_secret'] ?? '',
            'redirectUri'             => $this->config['redirect_uri'] ?? $baseUrl . 'api/sso.php?action=sso_callback&provider=' . ($this->providerData['id'] ?? 1),
            'urlAuthorize'            => $this->config['authorize_url'] ?? '',
            'urlAccessToken'          => $this->config['token_url'] ?? '',
            'urlResourceOwnerDetails' => $this->config['userinfo_url'] ?? '',
        ]);
    }
}
