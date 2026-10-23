<?php
declare(strict_types=1);

/**
 * Custom PHP Page Forms runtime helper class ($nu) for Nuvis StudioX.
 */
final class NuPage
{
    public const API_VERSION = 1;

    private ?array $userCache = null;

    public function apiVersion(): int
    {
        return self::API_VERSION;
    }

    public function db(): NuDatabase
    {
        return NuDatabase::getInstance();
    }

    public function user(): array
    {
        if ($this->userCache !== null) {
            return $this->userCache;
        }

        if (class_exists('NuAuth')) {
            $auth = NuAuth::getInstance();
            $curr = $auth->getCurrentUser();
            if ($curr) {
                return $this->userCache = $curr;
            }
        }

        return $this->userCache = [
            'usr_id' => $_SESSION['usr_id'] ?? $_SESSION['user_id'] ?? 'guest',
            'usr_name' => $_SESSION['usr_name'] ?? $_SESSION['username'] ?? 'Guest',
            'usr_role' => $_SESSION['usr_role'] ?? $_SESSION['role'] ?? 'user',
        ];
    }

    public function formUrl(string $code, mixed $id = null): string
    {
        $url = '?p=form&code=' . urlencode($code);
        if ($id !== null && $id !== '') {
            $url .= '&id=' . urlencode((string)$id);
        }
        return $url;
    }

    public function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
