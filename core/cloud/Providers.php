<?php
declare(strict_types=1);

require_once __DIR__ . '/CloudHttp.php';
require_once __DIR__ . '/CloudConfig.php';

/** Thrown for any provider failure; the message is safe to show to an administrator (never contains secrets). */
class NuCloudException extends RuntimeException {}

abstract class NuCloudProvider
{
    protected array $cfg;
    protected array $global;
    private static array $tokenCache = [];

    public function __construct(array $global)
    {
        $this->global = $global;
        $this->cfg = $global[$this->id()] ?? [];
    }

    abstract public function id(): string;
    abstract public function label(): string;
    /** true = needs the browser OAuth flow; false = credentials entered directly */
    public function usesOAuth(): bool { return true; }

    /** Endpoint, overridable through $nuConfig['cloud_endpoints'] (self-hosted gateways, tests). */
    protected function ep(string $key, string $default): string
    {
        global $nuConfig;
        return (string)($nuConfig['cloud_endpoints'][$this->id() . '.' . $key] ?? $default);
    }

    public function authUrl(string $redirectUri, string $state): string { throw new NuCloudException('This provider does not use browser sign-in.'); }
    /** @return array{refresh_token:string,account:string} */
    public function exchangeCode(string $code, string $redirectUri): array { throw new NuCloudException('This provider does not use browser sign-in.'); }

    /** Short-lived access token from the stored refresh token. */
    protected function accessToken(): string
    {
        $key = $this->id() . '|' . substr(hash('sha256', (string)($this->cfg['refresh_token'] ?? '')), 0, 12);
        $c = self::$tokenCache[$key] ?? null;
        if ($c && $c['exp'] > time() + 30) return $c['tok'];
        if (empty($this->cfg['refresh_token'])) throw new NuCloudException($this->label() . ' is not connected yet.');
        $r = NuCloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $this->refreshParams(), ['timeout' => 30]);
        $d = NuCloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['access_token'])) {
            if (($d['error'] ?? '') === 'invalid_grant') throw new NuCloudException($this->label() . ' access was revoked or expired. Connect the account again.');
            throw new NuCloudException($this->label() . ' sign-in failed (' . NuCloudHttp::reason($r) . ').');
        }
        if (!empty($d['refresh_token']) && $d['refresh_token'] !== $this->cfg['refresh_token']) {   // Microsoft rotates refresh tokens
            NuCloudConfig::setConnection($this->id(), ['refresh_token' => (string)$d['refresh_token']]);
            $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        }
        self::$tokenCache[$key] = ['tok' => (string)$d['access_token'], 'exp' => time() + (int)($d['expires_in'] ?? 3000)];
        return self::$tokenCache[$key]['tok'];
    }
    protected function tokenUrl(): string { return ''; }
    protected function refreshParams(): array { return []; }

    protected function bearer(): array { return ['Authorization: Bearer ' . $this->accessToken()]; }

    protected function fail(array $r, string $what): never
    {
        throw new NuCloudException("{$this->label()}: {$what} failed (" . NuCloudHttp::reason($r) . ').');
    }

    /** @param string[] $segments folder path, @return array{id:string,url:string} */
    abstract public function upload(string $localPath, array $segments, string $name, string $mime): array;
    /** Stream the remote file through $sink(chunk). @param string $remoteId id/path returned by upload() */
    abstract public function download(string $remoteId, callable $sink): void;
    abstract public function delete(string $remoteId): void;
    /** Verify the connection; returns a label for the connected account. */
    abstract public function whoami(): string;
}

final class NuGoogleDrive extends NuCloudProvider
{
    private array $folderIds = [];
    public function id(): string { return 'gdrive'; }
    public function label(): string { return 'Google Drive'; }
    protected function tokenUrl(): string { return $this->ep('token', 'https://oauth2.googleapis.com/token'); }
    protected function refreshParams(): array { return ['client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'refresh_token' => $this->cfg['refresh_token'], 'grant_type' => 'refresh_token']; }

    public function authUrl(string $redirectUri, string $state): string
    {
        // drive.file = the app can only see files it created itself (least privilege)
        return $this->ep('auth', 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query([
            'client_id' => $this->cfg['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $r = NuCloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], [
            'code' => $code, 'client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code']);
        $d = NuCloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['refresh_token'])) {
            throw new NuCloudException('Google did not return a refresh token (' . (empty($d['refresh_token']) && $r['status'] === 200 ? 'remove the app under your Google account permissions and connect again' : NuCloudHttp::reason($r)) . ').');
        }
        $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        $acct = ''; try { $acct = $this->whoami(); } catch (\Throwable $e) {}
        return ['refresh_token' => (string)$d['refresh_token'], 'account' => $acct];
    }

    public function whoami(): string
    {
        $r = NuCloudHttp::request('GET', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/about?fields=user(emailAddress,displayName)', $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 200) $this->fail($r, 'account check');
        $u = NuCloudHttp::json($r)['user'] ?? [];
        return (string)($u['emailAddress'] ?? $u['displayName'] ?? 'Google account');
    }

    private function folder(array $segments): string
    {
        $parent = 'root'; $path = '';
        foreach ($segments as $seg) {
            $path .= '/' . $seg;
            if (isset($this->folderIds[$path])) { $parent = $this->folderIds[$path]; continue; }
            $q = "mimeType='application/vnd.google-apps.folder' and trashed=false and name='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $seg) . "' and '{$parent}' in parents";
            $r = NuCloudHttp::request('GET', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1, 'spaces' => 'drive']), $this->bearer(), null, ['timeout' => 30]);
            if ($r['status'] !== 200) $this->fail($r, 'folder lookup');
            $id = NuCloudHttp::json($r)['files'][0]['id'] ?? null;
            if (!$id) {
                $r = NuCloudHttp::request('POST', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files?fields=id', array_merge($this->bearer(), ['Content-Type: application/json']),
                    json_encode(['name' => $seg, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent]]), ['timeout' => 30]);
                if ($r['status'] !== 200) $this->fail($r, 'folder creation');
                $id = NuCloudHttp::json($r)['id'] ?? null;
            }
            $this->folderIds[$path] = $parent = (string)$id;
        }
        return $parent;
    }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $parent = $this->folder($segments);
        $init = NuCloudHttp::request('POST', $this->ep('upload', 'https://www.googleapis.com') . '/upload/drive/v3/files?uploadType=resumable&fields=id,webViewLink',
            array_merge($this->bearer(), ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . filesize($localPath)]),
            json_encode(['name' => $name, 'parents' => [$parent]]), ['timeout' => 30]);
        $loc = $init['headers']['location'] ?? '';
        if ($init['status'] !== 200 || $loc === '') $this->fail($init, 'upload start');
        $r = NuCloudHttp::request('PUT', $loc, ['Content-Type: ' . $mime], null, ['infile' => $localPath, 'timeout' => 300]);
        if ($r['status'] < 200 || $r['status'] > 201) $this->fail($r, 'upload');
        $d = NuCloudHttp::json($r);
        if (empty($d['id'])) throw new NuCloudException('Google Drive: upload finished but no file id was returned.');
        return ['id' => (string)$d['id'], 'url' => (string)($d['webViewLink'] ?? '')];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = NuCloudHttp::request('GET', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files/' . rawurlencode($remoteId) . '?alt=media', $this->bearer(), null, ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = NuCloudHttp::request('DELETE', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files/' . rawurlencode($remoteId), $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 204 && $r['status'] !== 404) $this->fail($r, 'delete');
    }
}

final class NuOneDrive extends NuCloudProvider
{
    public function id(): string { return 'onedrive'; }
    public function label(): string { return 'OneDrive'; }
    private function tenant(): string { return (string)($this->cfg['tenant'] ?: 'common'); }
    protected function tokenUrl(): string { return $this->ep('token', 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/token'); }
    protected function refreshParams(): array { return ['client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'refresh_token' => $this->cfg['refresh_token'], 'grant_type' => 'refresh_token', 'scope' => 'Files.ReadWrite offline_access User.Read']; }
    private function api(): string { return $this->ep('api', 'https://graph.microsoft.com/v1.0'); }

    public function authUrl(string $redirectUri, string $state): string
    {
        return $this->ep('auth', 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/authorize') . '?' . http_build_query([
            'client_id' => $this->cfg['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'response_mode' => 'query',
            'scope' => 'Files.ReadWrite offline_access User.Read', 'state' => $state, 'prompt' => 'select_account']);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $r = NuCloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], [
            'code' => $code, 'client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code', 'scope' => 'Files.ReadWrite offline_access User.Read']);
        $d = NuCloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['refresh_token'])) throw new NuCloudException('Microsoft sign-in failed (' . NuCloudHttp::reason($r) . ').');
        $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        $acct = ''; try { $acct = $this->whoami(); } catch (\Throwable $e) {}
        return ['refresh_token' => (string)$d['refresh_token'], 'account' => $acct];
    }

    public function whoami(): string
    {
        $r = NuCloudHttp::request('GET', $this->api() . '/me?$select=userPrincipalName,mail,displayName', $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 200) $this->fail($r, 'account check');
        $d = NuCloudHttp::json($r);
        return (string)($d['userPrincipalName'] ?? $d['mail'] ?? $d['displayName'] ?? 'Microsoft account');
    }

    private function enc(array $segs): string { return implode('/', array_map('rawurlencode', $segs)); }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $size = filesize($localPath);
        $path = $this->enc(array_merge($segments, [$name]));
        if ($size <= 4 * 1024 * 1024) {
            $r = NuCloudHttp::request('PUT', $this->api() . '/me/drive/root:/' . $path . ':/content?@microsoft.graph.conflictBehavior=rename', array_merge($this->bearer(), ['Content-Type: ' . $mime]), null, ['infile' => $localPath, 'timeout' => 300]);
            if ($r['status'] !== 200 && $r['status'] !== 201) $this->fail($r, 'upload');
            $d = NuCloudHttp::json($r);
        } else {
            $s = NuCloudHttp::request('POST', $this->api() . '/me/drive/root:/' . $path . ':/createUploadSession', array_merge($this->bearer(), ['Content-Type: application/json']),
                json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'rename']]), ['timeout' => 30]);
            $url = NuCloudHttp::json($s)['uploadUrl'] ?? '';
            if ($s['status'] !== 200 || $url === '') $this->fail($s, 'upload start');
            $chunk = 327680 * 10; $off = 0; $d = [];
            while ($off < $size) {
                $len = min($chunk, $size - $off);
                $r = NuCloudHttp::request('PUT', $url, ['Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size, 'Content-Type: application/octet-stream'], null, ['body_from' => [$localPath, $off, $len], 'timeout' => 300]);
                if (!in_array($r['status'], [200, 201, 202], true)) $this->fail($r, 'upload');
                $off += $len; if ($r['status'] !== 202) $d = NuCloudHttp::json($r);
            }
        }
        if (empty($d['id'])) throw new NuCloudException('OneDrive: upload finished but no file id was returned.');
        return ['id' => (string)$d['id'], 'url' => (string)($d['webUrl'] ?? '')];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = NuCloudHttp::request('GET', $this->api() . '/me/drive/items/' . rawurlencode($remoteId) . '/content', $this->bearer(), null, ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = NuCloudHttp::request('DELETE', $this->api() . '/me/drive/items/' . rawurlencode($remoteId), $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 204 && $r['status'] !== 404) $this->fail($r, 'delete');
    }
}

final class NuDropbox extends NuCloudProvider
{
    public function id(): string { return 'dropbox'; }
    public function label(): string { return 'Dropbox'; }
    protected function tokenUrl(): string { return $this->ep('token', 'https://api.dropboxapi.com/oauth2/token'); }
    protected function refreshParams(): array { return ['client_id' => $this->cfg['app_key'], 'client_secret' => $this->cfg['app_secret'], 'refresh_token' => $this->cfg['refresh_token'], 'grant_type' => 'refresh_token']; }

    public function authUrl(string $redirectUri, string $state): string
    {
        return $this->ep('auth', 'https://www.dropbox.com/oauth2/authorize') . '?' . http_build_query([
            'client_id' => $this->cfg['app_key'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'token_access_type' => 'offline', 'state' => $state]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $r = NuCloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], [
            'code' => $code, 'client_id' => $this->cfg['app_key'], 'client_secret' => $this->cfg['app_secret'], 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code']);
        $d = NuCloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['refresh_token'])) throw new NuCloudException('Dropbox sign-in failed (' . NuCloudHttp::reason($r) . ').');
        $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        $acct = ''; try { $acct = $this->whoami(); } catch (\Throwable $e) {}
        return ['refresh_token' => (string)$d['refresh_token'], 'account' => $acct];
    }

    private function rpc(): string { return $this->ep('api', 'https://api.dropboxapi.com'); }
    private function content(): string { return $this->ep('content', 'https://content.dropboxapi.com'); }

    public function whoami(): string
    {
        $r = NuCloudHttp::request('POST', $this->rpc() . '/2/users/get_current_account', array_merge($this->bearer(), ['Content-Type:']), '', ['timeout' => 30]);
        if ($r['status'] !== 200) $this->fail($r, 'account check');
        $d = NuCloudHttp::json($r);
        return (string)($d['email'] ?? $d['name']['display_name'] ?? 'Dropbox account');
    }

    private static function argJson(array $a): string { return json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $path = '/' . implode('/', array_merge($segments, [$name]));
        // header values must be ASCII: escape non-ASCII per Dropbox's documentation
        $arg = preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', fn($m) => sprintf('\\u%04x', mb_ord($m[0])), self::argJson(['path' => $path, 'mode' => 'add', 'autorename' => true, 'mute' => true]));
        $r = NuCloudHttp::request('POST', $this->content() . '/2/files/upload', array_merge($this->bearer(), ['Dropbox-API-Arg: ' . $arg, 'Content-Type: application/octet-stream']), null, ['infile' => $localPath, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'upload');
        $d = NuCloudHttp::json($r);
        if (empty($d['id'])) throw new NuCloudException('Dropbox: upload finished but no file id was returned.');
        return ['id' => (string)$d['id'], 'url' => ''];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = NuCloudHttp::request('POST', $this->content() . '/2/files/download', array_merge($this->bearer(), ['Dropbox-API-Arg: ' . self::argJson(['path' => $remoteId]), 'Content-Type:']), '', ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = NuCloudHttp::request('POST', $this->rpc() . '/2/files/delete_v2', array_merge($this->bearer(), ['Content-Type: application/json']), json_encode(['path' => $remoteId]), ['timeout' => 30]);
        if ($r['status'] !== 200 && $r['status'] !== 409) $this->fail($r, 'delete');   // 409 = already gone
    }
}

/** WebDAV: Nextcloud, ownCloud, Synology, Box, any DAV server. Basic auth (use an app password). */
final class NuWebDav extends NuCloudProvider
{
    public function id(): string { return 'webdav'; }
    public function label(): string { return 'WebDAV'; }
    public function usesOAuth(): bool { return false; }
    private function auth(): array { return ['Authorization: Basic ' . base64_encode($this->cfg['username'] . ':' . $this->cfg['password'])]; }
    private function url(array $segs): string { return rtrim((string)$this->cfg['url'], '/') . ($segs ? '/' . implode('/', array_map('rawurlencode', $segs)) : ''); }

    public function whoami(): string
    {
        if (empty($this->cfg['url'])) throw new NuCloudException('WebDAV address is missing.');
        $r = NuCloudHttp::request('PROPFIND', $this->url([]) . '/', array_merge($this->auth(), ['Depth: 0']), null, ['timeout' => 30]);
        if ($r['status'] === 401) throw new NuCloudException('WebDAV: the username or password was rejected.');
        if ($r['status'] !== 207 && $r['status'] !== 200) $this->fail($r, 'connection check');
        return $this->cfg['username'] . '@' . (parse_url((string)$this->cfg['url'], PHP_URL_HOST) ?: 'server');
    }

    private function mkdirs(array $segments): void
    {
        $acc = [];
        foreach ($segments as $seg) {
            $acc[] = $seg;
            $r = NuCloudHttp::request('MKCOL', $this->url($acc) . '/', $this->auth(), null, ['timeout' => 30]);
            if (!in_array($r['status'], [201, 405, 301, 200], true)) $this->fail($r, 'folder creation');   // 405 = already exists
        }
    }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $this->mkdirs($segments);
        $segs = array_merge($segments, [$name]);
        $r = NuCloudHttp::request('PUT', $this->url($segs), array_merge($this->auth(), ['Content-Type: ' . $mime]), null, ['infile' => $localPath, 'timeout' => 300]);
        if (!in_array($r['status'], [200, 201, 204], true)) $this->fail($r, 'upload');
        return ['id' => implode('/', $segs), 'url' => ''];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = NuCloudHttp::request('GET', $this->url(explode('/', $remoteId)), $this->auth(), null, ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = NuCloudHttp::request('DELETE', $this->url(explode('/', $remoteId)), $this->auth(), null, ['timeout' => 30]);
        if (!in_array($r['status'], [200, 204, 404], true)) $this->fail($r, 'delete');
    }
}

final class NuCloud
{
    /** Provider object for the active (or given) provider, or null for local-only storage. */
    public static function provider(?string $id = null, ?array $cfg = null): ?NuCloudProvider
    {
        $cfg = $cfg ?? NuCloudConfig::get();
        $id = $id ?? $cfg['provider'];
        switch ($id) {
            case 'gdrive':   return new NuGoogleDrive($cfg);
            case 'onedrive': return new NuOneDrive($cfg);
            case 'dropbox':  return new NuDropbox($cfg);
            case 'webdav':   return new NuWebDav($cfg);
            default:         return null;
        }
    }

    public static function labels(): array
    {
        return ['local' => 'This server only', 'gdrive' => 'Google Drive', 'onedrive' => 'OneDrive', 'dropbox' => 'Dropbox', 'webdav' => 'WebDAV (Nextcloud, ownCloud…)'];
    }
}
