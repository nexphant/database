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
        $this->runAfterConnect();
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
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => $config['emulate_prepares'] ?? in_array(($config['driver'] ?? ''), ['mysql', 'mariadb'], true),
            PDO::ATTR_PERSISTENT => $config['persistent'] ?? true,
            PDO::ATTR_TIMEOUT => max(1, (int) ($config['connect_timeout'] ?? 5)),
        ];
        foreach (($config['pdo_options'] ?? []) as $key => $value) {
            $options[$key] = $value;
        }
        return $options;
    }

    private function configure(): void
    {
        $driver = $this->config['driver'] ?? '';
        if ($driver === 'sqlite') {
            $this->configureSqlite();
        }
        foreach (($this->config['init_commands'] ?? $this->config[$driver . '_init_commands'] ?? []) as $sql) {
            $this->connection()->exec((string) $sql);
        }
        foreach (($this->config['session'] ?? $this->config[$driver . '_session'] ?? []) as $name => $value) {
            $this->connection()->exec($this->sessionSql($driver, (string) $name, $value));
        }
    }

    private function configureSqlite(): void
    {
        $profile = $this->config['sqlite_profile'] ?? $this->config['profile'] ?? 'fast';
        $pragmas = $profile === false || $profile === 'none'
            ? []
            : $this->sqlitePragmas((string) $profile);
        foreach (($this->config['sqlite_pragmas'] ?? $this->config['pragmas'] ?? []) as $name => $value) {
            if ($value === null) {
                unset($pragmas[$name]);
                continue;
            }
            $pragmas[$name] = $value;
        }
        foreach ($pragmas as $name => $value) {
            if (is_int($name)) {
                $this->connection()->exec((string) $value);
                continue;
            }
            $this->connection()->exec('PRAGMA ' . $name . ' = ' . $this->sqlValue($value));
        }
        if (($this->config['sqlite_optimize'] ?? false) === true) {
            $this->connection()->exec('PRAGMA optimize');
        }
    }

    private function sqlitePragmas(string $profile): array
    {
        return match ($profile) {
            'safe' => [
                'journal_mode' => 'WAL',
                'synchronous' => 'FULL',
                'foreign_keys' => 'ON',
                'busy_timeout' => max(1, (int) ($this->config['busy_timeout_ms'] ?? 5000)),
            ],
            'memory' => [
                'journal_mode' => 'MEMORY',
                'synchronous' => 'OFF',
                'foreign_keys' => 'ON',
                'temp_store' => 'MEMORY',
                'cache_size' => (int) ($this->config['cache_size'] ?? -50000),
                'mmap_size' => (int) ($this->config['mmap_size'] ?? 536870912),
            ],
            default => [
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
                'foreign_keys' => 'ON',
                'busy_timeout' => max(1, (int) ($this->config['busy_timeout_ms'] ?? 5000)),
                'temp_store' => 'MEMORY',
                'cache_size' => (int) ($this->config['cache_size'] ?? -20000),
            ],
        };
    }

    private function sessionSql(string $driver, string $name, mixed $value): string
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return 'SET SESSION ' . $name . ' = ' . $this->sqlValue($value);
        }
        if ($driver === 'pgsql') {
            return 'SET ' . $name . ' = ' . $this->sqlValue($value);
        }
        return $name . ' = ' . $this->sqlValue($value);
    }

    private function sqlValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'ON' : 'OFF';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $this->connection()->quote((string) $value);
    }

    private function runAfterConnect(): void
    {
        if (($this->config['after_connect'] ?? null) !== null) {
            ($this->config['after_connect'])($this->connection(), $this->config);
        }
    }

    private function returnsRows(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'PRAGMA', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);
    }
}
