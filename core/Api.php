<?php
// Nuvis StudioX - API Layer

class NuApi {
    private $db;
    private $config;
    private $auth;
    private $pkCache = [];

    public function __construct() {
        global $nuConfig;
        $this->config = $nuConfig;
        $this->db = NuDatabase::getInstance();
        $this->auth = new NuAuth();
    }

    /**
     * Validates that an identifier (table name or column name) contains only alphanumeric characters and underscores,
     * and optionally checks if the table exists in the database schema.
     */
    public function validateIdentifier(string $identifier, bool $checkTableExists = false): string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid table or column name format');
        }

        if ($checkTableExists) {
            $driver = $this->db->getDriverName();
            if ($driver === 'sqlite') {
                $check = $this->db->fetchOne(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = :t",
                    [':t' => $identifier]
                );
            } else {
                $check = $this->db->fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t",
                    [':t' => $identifier]
                );
            }
            if (!$check) {
                throw new InvalidArgumentException('Access denied or table does not exist');
            }
        }

        return $identifier;
    }

    public function authenticate() {
        if (isset($_SESSION['nu_user_id'])) {
            $user = $this->db->fetchOne(
                "SELECT usr_id as token_user_id, usr_username, usr_role FROM nu_users WHERE usr_id = :id AND usr_active = 1",
                [':id' => $_SESSION['nu_user_id']]
            );
            if ($user) return $user;
        }

        $allowQueryParam = $this->config['allowApiKeyQueryParam'] ?? false;
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if (!$apiKey && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
                $apiKey = $matches[1];
            }
        }
        if (!$apiKey && $allowQueryParam && isset($_GET['api_key'])) {
            $apiKey = $_GET['api_key'];
        }

        if (!$apiKey) {
            $this->error('API key required', 401);
        }

        $token = $this->db->fetchOne(
            "SELECT t.*, u.usr_username, u.usr_role FROM nu_api_tokens t
             JOIN nu_users u ON t.token_user_id = u.usr_id
             WHERE t.token_key = :key AND t.token_active = 1 AND (t.token_expires IS NULL OR t.token_expires > NOW())",
            [':key' => $apiKey]
        );

        if (!$token) {
            $this->error('Invalid or expired API key', 401);
        }

        $this->checkRateLimit($token['token_user_id']);

        return $token;
    }

    public function response($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public function error($message, $code = 400) {
        $this->response(['success' => false, 'error' => $message], $code);
    }

    public function success($data = null, $message = 'Success') {
        $this->response(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private function checkRateLimit($userId) {
        $hour = date('Y-m-d H:00:00');
        $this->db->query(
            "INSERT INTO nu_api_usage (usage_user_id, usage_hour, usage_count)
             VALUES (:uid, :hour, 1)
             ON DUPLICATE KEY UPDATE usage_count = usage_count + 1",
            [':uid' => $userId, ':hour' => $hour]
        );

        $usage = $this->db->fetchOne(
            "SELECT usage_count FROM nu_api_usage WHERE usage_user_id = :uid AND usage_hour = :hour",
            [':uid' => $userId, ':hour' => $hour]
        );

        if ($usage && $usage['usage_count'] > $this->config['apiRateLimit']) {
            $this->error('Rate limit exceeded', 429);
        }
    }

    // Detect primary key column for any table
    private function getPrimaryKey($table) {
        $table = $this->validateIdentifier($table, true);
        if (isset($this->pkCache[$table])) {
            return $this->pkCache[$table];
        }

        $driver = $this->db->getDriverName();
        if ($driver === 'sqlite') {
            $cols = $this->db->fetchAll("PRAGMA table_info(`{$table}`)");
            foreach ($cols as $col) {
                if (!empty($col['pk'])) {
                    $this->pkCache[$table] = $col['name'];
                    return $col['name'];
                }
            }
        } else {
            $cols = $this->db->fetchAll("SHOW COLUMNS FROM `{$table}`");
            foreach ($cols as $col) {
                if ($col['Key'] === 'PRI') {
                    $this->pkCache[$table] = $col['Field'];
                    return $col['Field'];
                }
            }
        }
        // Fallback to 'id' if no PK found
        $this->pkCache[$table] = 'id';
        return 'id';
    }

    public function listRecords($table, $filters = [], $page = 1, $perPage = 20) {
        $table = $this->validateIdentifier($table, true);
        $offset = ($page - 1) * $perPage;
        $where = ['1=1'];
        $params = [];

        foreach ($filters as $key => $value) {
            $cleanKey = $this->validateIdentifier((string)$key, false);
            $where[] = "`{$cleanKey}` = :{$cleanKey}";
            $params[":{$cleanKey}"] = $value;
        }

        $sql = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $where) .
               " LIMIT :limit OFFSET :offset";
        $params[':limit'] = (int)$perPage;
        $params[':offset'] = (int)$offset;

        $records = $this->db->fetchAll($sql, $params);

        $countSql = "SELECT COUNT(*) as total FROM `{$table}` WHERE " . implode(' AND ', $where);
        unset($params[':limit'], $params[':offset']);
        $total = $this->db->fetchOne($countSql, $params);

        return [
            'records' => $records,
            'total' => $total['total'],
            'page' => $page,
            'per_page' => $perPage,
            'pages' => ceil($total['total'] / $perPage)
        ];
    }

    public function getRecord($table, $id) {
        $table = $this->validateIdentifier($table, true);
        $pk = $this->getPrimaryKey($table);
        return $this->db->fetchOne("SELECT * FROM `{$table}` WHERE `{$pk}` = :id", [':id' => $id]);
    }

    public function createRecord($table, $data) {
        $table = $this->validateIdentifier($table, true);
        $cleanData = [];
        foreach ($data as $k => $v) {
            $cleanKey = $this->validateIdentifier((string)$k, false);
            $cleanData[$cleanKey] = $v;
        }
        $id = $this->db->insert($table, $cleanData);
        $this->logAudit('api_create', $table, $id, null, $cleanData);
        return $id;
    }

    public function updateRecord($table, $id, $data) {
        $table = $this->validateIdentifier($table, true);
        $pk = $this->getPrimaryKey($table);
        $old = $this->getRecord($table, $id);
        $cleanData = [];
        foreach ($data as $k => $v) {
            $cleanKey = $this->validateIdentifier((string)$k, false);
            $cleanData[$cleanKey] = $v;
        }
        $this->db->update($table, $cleanData, "`{$pk}` = :id", [':id' => $id]);
        $this->logAudit('api_update', $table, $id, $old, $cleanData);
        return true;
    }

    public function deleteRecord($table, $id) {
        $table = $this->validateIdentifier($table, true);
        $pk = $this->getPrimaryKey($table);
        $old = $this->getRecord($table, $id);
        $this->db->delete($table, "`{$pk}` = :id", [':id' => $id]);
        $this->logAudit('api_delete', $table, $id, $old, null);
        return true;
    }

    private function logAudit($action, $table, $recordId, $old = null, $new = null) {
        if (!$this->config['enableAuditTrail']) return;
        $audit = new NuAudit();
        $audit->log($action, $table, $recordId, $old, $new);
    }
}
?>