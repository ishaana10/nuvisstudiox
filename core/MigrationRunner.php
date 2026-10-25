<?php
declare(strict_types=1);

class MigrationRunner
{
    private PDO $pdo;
    private string $migrationsPath;

    public function __construct(PDO $pdo, ?string $migrationsPath = null)
    {
        $this->pdo = $pdo;
        $this->migrationsPath = $migrationsPath ?? (defined('NU_ROOT') ? NU_ROOT : dirname(__DIR__)) . '/db/migrations';
    }

    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->getAppliedVersions();
        $files = glob($this->migrationsPath . '/*.php');
        if ($files === false) {
            $files = [];
        }
        sort($files);

        $executed = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (in_array($version, $applied, true)) {
                continue;
            }

            $migration = require $file;
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            // Check if transaction is supported/needed. Some DDLs in MySQL auto-commit transactions.
            $useTx = ($driver === 'sqlite' || $driver === 'mysql');
            if ($useTx && !$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            try {
                if (is_object($migration) && method_exists($migration, 'up')) {
                    $migration->up($this->pdo);
                }

                $nowSql = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
                $stmt = $this->pdo->prepare("INSERT INTO nu_migrations (version, applied_at) VALUES (?, {$nowSql})");
                $stmt->execute([$version]);

                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                $executed[] = $version;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration $version failed: " . $e->getMessage(), 0, $e);
            }
        }
        return $executed;
    }

    private function ensureMigrationsTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS nu_migrations (
                    version VARCHAR(100) PRIMARY KEY,
                    applied_at DATETIME NOT NULL
                )
            ");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS nu_migrations (
                    version VARCHAR(100) PRIMARY KEY,
                    applied_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    private function getAppliedVersions(): array
    {
        $stmt = $this->pdo->query("SELECT version FROM nu_migrations ORDER BY version");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }
}
