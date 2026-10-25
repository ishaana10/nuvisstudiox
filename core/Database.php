<?php
declare(strict_types=1);
/**
 * NuDatabase - PDO wrapper (singleton)
 * PHP 8.1+ compatible
 * IMPORTANT: destructor must NOT touch session - it runs during PHP shutdown
 * after session_write_close() has already been called.
 */
class NuDatabase {
    private static $instance = null;
    private $pdo;
    private $config;

    private function __construct() {
        global $nuConfig;
        if (empty($nuConfig)) {
            $configPath = defined('NU_ROOT') ? NU_ROOT . '/config.php' : dirname(__DIR__) . '/config.php';
            if (is_file($configPath)) {
                require_once $configPath;
            }
        }
        $this->config = $nuConfig ?? [];
        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function getConnection() {
        return self::getInstance()->pdo;
    }

    private function connect() {
        $dbType = $this->config['dbDriver'] ?? $this->config['dbType'] ?? 'mysql';
        $dbName = (string)($this->config['dbName'] ?? '');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ];

        if ($dbType === 'sqlite' || (str_ends_with($dbName, '.db') || str_ends_with($dbName, '.sqlite')) || (!empty($this->config['dbSqlitePath']) && is_file($this->config['dbSqlitePath']))) {
            $sqliteFile = !empty($this->config['dbSqlitePath']) ? $this->config['dbSqlitePath'] : $dbName;
            $dsn = "sqlite:" . $sqliteFile;
            $user = null;
            $pass = null;
        } else {
            $host    = $this->config['dbHost']     ?? 'localhost';
            $user    = $this->config['dbUser']     ?? '';
            $pass    = $this->config['dbPassword'] ?? '';
            $charset = $this->config['dbCharset']  ?? 'utf8mb4';
            $port    = $this->config['dbPort']     ?? 3306;
            $dsn     = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
        }

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }

        // Schema version check against session
        $sessionActive = (session_status() === PHP_SESSION_ACTIVE);
        if ($sessionActive) {
            $_SESSION['_nu_schema_version'] = defined('NU_VERSION') ? NU_VERSION : '1.0.0';
        }
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function getDriverName(): string {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Run a DML query (SELECT / INSERT / UPDATE / DELETE) via a prepared statement.
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Run a DDL statement (CREATE TABLE, ALTER TABLE, DROP TABLE, etc.)
     * directly via PDO::exec() — bypasses the prepared-statement layer.
     * MySQL cannot run DDL through native prepared statements.
     * Throws PDOException on failure.
     */
    public function exec(string $sql) {
        $result = $this->pdo->exec($sql);
        if ($result === false) {
            $err = $this->pdo->errorInfo();
            throw new \PDOException('DDL exec failed [' . ($err[0] ?? '') . ']: ' . ($err[2] ?? 'unknown error'));
        }
        return $result;
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    // PHP 8.1+ compatible
    public function insert($table, $data) {
        $cols         = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(function($k) { return ":$k"; }, array_keys($data)));
        $params       = [];
        foreach ($data as $k => $v) {
            $params[":$k"] = $v;
        }
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
        $this->query($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    // PHP 8.1+ compatible
    public function update($table, $data, $where, $whereParams = []) {
        $sets   = implode(', ', array_map(function($k) { return "{$k} = :set_{$k}"; }, array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params[":set_{$k}"] = $v;
        }

        if (!empty($whereParams) && array_keys($whereParams) === range(0, count($whereParams) - 1)) {
            $i = 0;
            $where = preg_replace_callback('/\?/', function() use (&$i) {
                return ':where_' . $i++;
            }, $where);
            $namedWhere = [];
            foreach ($whereParams as $idx => $val) {
                $namedWhere[':where_' . $idx] = $val;
            }
            $whereParams = $namedWhere;
        }

        $sql  = "UPDATE {$table} SET {$sets} WHERE {$where}";
        $stmt = $this->query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    public function delete($table, $where, $params = []) {
        return $this->query("DELETE FROM {$table} WHERE {$where}", $params)->rowCount();
    }

    public function lastInsertId() {
        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction() { $this->pdo->beginTransaction(); }
    public function commit()           { $this->pdo->commit(); }
    public function rollback()         { $this->pdo->rollBack(); }

    public function __destruct() {
        $this->pdo = null;
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize NuDatabase.'); }
}

if (!class_exists('Database')) {
    class_alias('NuDatabase', 'Database');
}

/**
 * NuProcedure Helper Class
 * Allows executing saved custom PHP functions from other server-side contexts.
 */
class NuProcedure {
    /**
     * Executes a saved custom PHP function (procedure) by its code.
     *
     * @param string $code
     * @param array $params
     * @param array $hashCookies
     * @return array
     */
    public static function run($code, $params = [], $hashCookies = []) {
        $db = NuDatabase::getInstance();
        $proc = $db->fetchOne("SELECT * FROM nu_procedures WHERE procedure_code = ? AND procedure_active = 1", [$code]);
        if (!$proc) {
            return [
                'success' => false,
                'error' => "Procedure not found or is inactive: " . $code
            ];
        }

        // Setup sandboxed execution variables
        $_proc_params = $params;
        $_proc_db     = $db;
        $_proc_auth   = class_exists('NuAuth') ? NuAuth::getInstance() : null;
        $_proc_hash   = $hashCookies;
        $_proc_result = null;

        ob_start();
        try {
            eval('?>' . $proc['procedure_php']);
            $output = ob_get_clean();
            return [
                'success' => true,
                'output'  => $output,
                'data'    => $_proc_result
            ];
        } catch (Throwable $e) {
            ob_end_clean();
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }
}

if (!function_exists('nu_run_procedure')) {
    function nu_run_procedure($code, $params = [], $hashCookies = []) {
        return NuProcedure::run($code, $params, $hashCookies);
    }
}

if (!function_exists('run_procedure')) {
    function run_procedure($code, $params = [], $hashCookies = []) {
        return NuProcedure::run($code, $params, $hashCookies);
    }
}
