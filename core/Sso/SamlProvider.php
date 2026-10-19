<?php
declare(strict_types=1);

namespace Nuvis\Sso;

use OneLogin\Saml2\Auth as SamlAuth;

class SamlProvider implements SsoProviderInterface {
    private array $config;
    private array $providerData;

    public function __construct(array $providerData) {
        $this->providerData = $providerData;
        $this->config = is_array($providerData['config_json'] ?? null)
            ? $providerData['config_json']
            : (json_decode($providerData['config_json'] ?? '{}', true) ?: []);
    }

    public function getLoginUrl(): string {
        $samlSettings = $this->buildSamlSettings();
        try {
            $samlAuth = new SamlAuth($samlSettings);
            return $samlAuth->login(null, [], false, false, true);
        } catch (\Throwable $e) {
            return 'index.php?sso_error=' . urlencode($e->getMessage());
        }
    }

    public function handleCallback(): array {
        $samlSettings = $this->buildSamlSettings();
        try {
            $samlAuth = new SamlAuth($samlSettings);
            $samlAuth->processResponse();
            $errors = $samlAuth->getErrors();
            if (!empty($errors)) {
                return ['success' => false, 'error' => 'SAML Error: ' . implode(', ', $errors)];
            }
            if (!$samlAuth->isAuthenticated()) {
                return ['success' => false, 'error' => 'Not authenticated by SAML IdP'];
            }

            $attributes = $samlAuth->getAttributes();
            $nameId     = $samlAuth->getNameId();

            $mapped = [
                'email'     => $attributes['email'][0] ?? $attributes['mail'][0] ?? $nameId,
                'username'  => $attributes['username'][0] ?? $attributes['uid'][0] ?? $nameId,
                'name'      => $attributes['name'][0] ?? $attributes['cn'][0] ?? ($attributes['first_name'][0] ?? '') . ' ' . ($attributes['last_name'][0] ?? ''),
                'role'      => $attributes['role'][0] ?? 'user',
                'sso_id'    => $nameId,
                'raw'       => $attributes,
            ];

            return ['success' => true, 'attributes' => array_filter($mapped)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getLogoutUrl(?string $nameId = null): ?string {
        $samlSettings = $this->buildSamlSettings();
        try {
            $samlAuth = new SamlAuth($samlSettings);
            return $samlAuth->logout(null, [], $nameId, null, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildSamlSettings(): array {
        $baseUrl = rtrim($GLOBALS['nuConfig']['baseUrl'] ?? 'http://localhost/', '/') . '/';
        return [
            'strict' => false,
            'sp' => [
                'entityId' => $this->config['sp_entity_id'] ?? $baseUrl . 'api/sso.php?action=saml_metadata',
                'assertionConsumerService' => [
                    'url' => $this->config['sp_acs_url'] ?? $baseUrl . 'api/sso.php?action=sso_callback&provider=' . ($this->providerData['id'] ?? 1),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
            ],
            'idp' => [
                'entityId' => $this->config['idp_entity_id'] ?? '',
                'singleSignOnService' => [
                    'url' => $this->config['idp_sso_url'] ?? '',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $this->config['idp_cert'] ?? '',
            ],
        ];
    }
}
