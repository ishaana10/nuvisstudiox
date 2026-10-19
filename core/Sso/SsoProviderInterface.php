<?php
declare(strict_types=1);

namespace Nuvis\Sso;

interface SsoProviderInterface {
    public function getLoginUrl(): string;
    public function handleCallback(): array;
    public function getLogoutUrl(?string $nameId = null): ?string;
}
