<?php

namespace Nexph\Database\Drivers;

use Nexph\Database\QueryLogger;
use PDO;

class PdoDriver implements DriverInterface
{
    private ?PDO $pdo = null;
    private array $config = [];
    private array $statementCache = [];
    private int $statementCacheSize = 128;
    private int $slowQueryMs = 100;
    private array $stats = [
        'queries' => 0,
        'writes' => 0,
        'errors' => 0,
        'total_ms' => 0.0,
        'max_ms' => 0.0,
        'slow_queries' => 0,
        'statement_hits' => 0,
        'statement_misses' => 0,
        'transactions' => 0,
        'rollbacks' => 0,
    ];

    public function connect(array $config): void
    {
        $this->config = $config;
        $this->statementCacheSize = max(0, (int) ($config['statement_cache_size'] ?? 128));
        $this->slowQueryMs = max(1, (int) ($config['slow_query_ms'] ?? 100));
        $this->pdo = new PDO($this->dsn($config), $config['username'] ?? null, $config['password'] ?? null, $this->options($config));
        $this->configure();
    }

    public function query(string $sql, array $params = []): DriverResult
    {
        return $this->run($sql, $params, false);
    }

    public function execute(string $sql, array $params = []): DriverResult
    {
        return $this->run($sql, $params, true);
    }

    public function statement(string $sql, array $params = []): \PDOStatement
    {
        $start = microtime(true);
        try {
            $stmt = $this->prepare($sql);
            $stmt->execute($params);
            $this->record($sql, $params, $start, !$this->returnsRows($sql));
            return $stmt;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }

    public function lastInsertId(): string
    {
        return (string) $this->connection()->lastInsertId();
    }

    public function begin(): void
    {
        $this->connection()->beginTransaction();
        $this->stats['transactions']++;
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollback(): void
    {
        $this->connection()->rollBack();
        $this->stats['rollbacks']++;
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->statementCache = [];
    }

    public function stats(): array
    {
        return $this->stats + [
            'driver' => $this->config['driver'] ?? 'pdo',
            'engine' => 'pdo',
            'async' => false,
            'connections' => $this->pdo ? 1 : 0,
            'statements_cached' => count($this->statementCache),
            'statement_cache_size' => $this->statementCacheSize,
            'slow_query_ms' => $this->slowQueryMs,
            'avg_ms' => $this->stats['queries'] > 0 ? $this->stats['total_ms'] / $this->stats['queries'] : 0.0,
        ];
    }

    protected function connection(): PDO
    {
        if (!$this->pdo) {
            throw new \RuntimeException('Database not connected');
        }
        return $this->pdo;
    }

    protected function run(string $sql, array $params, bool $write): DriverResult
    {
        $start = microtime(true);
        try {
            $stmt = $this->prepare($sql);
            $stmt->execute($params);
            $rows = $this->returnsRows($sql) ? $stmt->fetchAll() : [];
            $durationMs = $this->record($sql, $params, $start, $write);
            return new DriverResult($rows, $stmt->rowCount(), $this->lastInsertId(), $durationMs);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }

    private function prepare(string $sql): \PDOStatement
    {
        $key = sha1($sql);
        if (isset($this->statementCache[$key])) {
            $this->stats['statement_hits']++;
            return $this->statementCache[$key];
        }
        $this->stats['statement_misses']++;
        $stmt = $this->connection()->prepare($sql);
        if ($this->statementCacheSize > 0) {
            if (count($this->statementCache) >= $this->statementCacheSize) {
                array_shift($this->statementCache);
            }
            $this->statementCache[$key] = $stmt;
        }
        return $stmt;
    }

    private function record(string $sql, array $params, float $start, bool $write): float
    {
        $durationMs = (microtime(true) - $start) * 1000;
        $this->stats['queries']++;
        if ($write) {
            $this->stats['writes']++;
        }
        $this->stats['total_ms'] += $durationMs;
        $this->stats['max_ms'] = max($this->stats['max_ms'], $durationMs);
        if ($durationMs > $this->slowQueryMs) {
            $this->stats['slow_queries']++;
        }
        QueryLogger::log($sql, $params, $durationMs / 1000);
        return $durationMs;
    }

    private function dsn(array $config): string
    {
        return match ($config['driver'] ?? 'sqlite') {
            'sqlite' => 'sqlite:' . $config['database'],
            'mysql', 'mariadb' => $this->mysqlDsn($config),
            'pgsql' => $this->pgsqlDsn($config),
            default => throw new \InvalidArgumentException('Unsupported DB driver: ' . ($config['driver'] ?? '')),
        };
    }

    private function mysqlDsn(array $config): string
    {
        $port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
        return 'mysql:host=' . ($config['host'] ?? '127.0.0.1') . $port . ';dbname=' . ($config['database'] ?? '') . ';charset=' . ($config['charset'] ?? 'utf8mb4');
    }

    private function pgsqlDsn(array $config): string
    {
        $port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
        return 'pgsql:host=' . ($config['host'] ?? '127.0.0.1') . $port . ';dbname=' . ($config['database'] ?? '');
    }

    private function options(array $config): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => $config['emulate_prepares'] ?? in_array(($config['driver'] ?? ''), ['mysql', 'mariadb'], true),
            PDO::ATTR_PERSISTENT => $config['persistent'] ?? true,
            PDO::ATTR_TIMEOUT => max(1, (int) ($config['connect_timeout'] ?? 5)),
        ];
    }

    private function configure(): void
    {
        if (($this->config['driver'] ?? '') !== 'sqlite') {
            return;
        }
        $pdo = $this->connection();
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . max(1, (int) ($this->config['busy_timeout_ms'] ?? 5000)));
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA cache_size = ' . (int) ($this->config['cache_size'] ?? -20000));
    }

    private function returnsRows(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'PRAGMA', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);
    }
}
