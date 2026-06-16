<?php

namespace Nexphant\Database\Drivers;

use Nexphant\Database\QueryLogger;
use SQLite3;

class SqliteDriver implements DriverInterface
{
    private ?SQLite3 $db = null;
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
        QueryLogger::setContext('sqlite', $config['pool_name'] ?? '');
        $this->db = new SQLite3((string) $config['database']);
        $this->applyPragmas($config);
        $this->runAfterConnect($config);
    }

    public function query(string $sql, array $params = []): DriverResult
    {
        return $this->run($sql, $params, false);
    }

    public function execute(string $sql, array $params = []): DriverResult
    {
        return $this->run($sql, $params, true);
    }

    public function lastInsertId(): string
    {
        return (string) $this->connection()->lastInsertRowID();
    }

    public function nativeConnection(): SQLite3
    {
        return $this->connection();
    }

    public function begin(): void
    {
        $this->connection()->exec('BEGIN');
        $this->stats['transactions']++;
    }

    public function commit(): void
    {
        $this->connection()->exec('COMMIT');
    }

    public function rollback(): void
    {
        $this->connection()->exec('ROLLBACK');
        $this->stats['rollbacks']++;
    }

    public function close(): void
    {
        foreach ($this->statementCache as $stmt) {
            $stmt->close();
        }
        $this->statementCache = [];
        $this->db?->close();
        $this->db = null;
    }

    public function stats(): array
    {
        return $this->stats + [
            'driver' => 'sqlite',
            'engine' => 'native',
            'async' => false,
            'connections' => $this->db ? 1 : 0,
            'statements_cached' => count($this->statementCache),
            'statement_cache_size' => $this->statementCacheSize,
            'slow_query_ms' => $this->slowQueryMs,
            'avg_ms' => $this->stats['queries'] > 0 ? $this->stats['total_ms'] / $this->stats['queries'] : 0.0,
        ];
    }

    private function run(string $sql, array $params, bool $write): DriverResult
    {
        $start = microtime(true);
        try {
            $stmt = $this->prepare($sql);
            $stmt->reset();
            $stmt->clear();
            foreach (array_values($params) as $i => $value) {
                $stmt->bindValue($i + 1, $value, $this->type($value));
            }
            $result = $stmt->execute();
            $rows = [];
            if ($result instanceof \SQLite3Result && $this->returnsRows($sql)) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $rows[] = $row;
                }
                $result->finalize();
            }
            $stmt->reset();
            $stmt->clear();
            $durationMs = $this->record($sql, $params, $start, $write);
            return new DriverResult($rows, $this->connection()->changes(), $this->lastInsertId(), $durationMs);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }

    private function connection(): SQLite3
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not connected');
        }
        return $this->db;
    }

    private function applyPragmas(array $config): void
    {
        $busyTimeout = $config['busy_timeout_ms'] ?? null;
        if ($busyTimeout !== null) {
            $this->connection()->busyTimeout(max(1, (int) $busyTimeout));
        }
        $profile = $config['sqlite_profile'] ?? $config['profile'] ?? 'fast';
        $pragmas = $profile === false || $profile === 'none'
            ? []
            : $this->sqlitePragmas((string) $profile, $config);
        foreach (($config['sqlite_pragmas'] ?? $config['pragmas'] ?? []) as $name => $value) {
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
            $this->connection()->exec('PRAGMA ' . $name . ' = ' . $this->pragmaValue($value));
        }
        if (($config['sqlite_optimize'] ?? true) !== false) {
            $this->connection()->exec('PRAGMA optimize');
        }
    }

    private function sqlitePragmas(string $profile, array $config): array
    {
        return match ($profile) {
            'safe' => [
                'journal_mode' => 'WAL',
                'synchronous' => 'FULL',
                'foreign_keys' => 'ON',
                'busy_timeout' => max(1, (int) ($config['busy_timeout_ms'] ?? 5000)),
            ],
            'memory' => [
                'journal_mode' => 'MEMORY',
                'synchronous' => 'OFF',
                'foreign_keys' => 'ON',
                'temp_store' => 'MEMORY',
                'cache_size' => (int) ($config['cache_size'] ?? -50000),
                'mmap_size' => (int) ($config['mmap_size'] ?? 536870912),
            ],
            default => [
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
                'foreign_keys' => 'ON',
                'busy_timeout' => max(1, (int) ($config['busy_timeout_ms'] ?? 5000)),
                'temp_store' => 'MEMORY',
                'cache_size' => (int) ($config['cache_size'] ?? -20000),
                'mmap_size' => (int) ($config['mmap_size'] ?? 268435456),
                'journal_size_limit' => (int) ($config['journal_size_limit'] ?? 67108864),
            ],
        };
    }

    private function pragmaValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'ON' : 'OFF';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    private function runAfterConnect(array $config): void
    {
        if (($config['after_connect'] ?? null) !== null) {
            ($config['after_connect'])($this->connection(), $config);
        }
    }

    private function prepare(string $sql): \SQLite3Stmt
    {
        $key = sha1($sql);
        if (isset($this->statementCache[$key])) {
            $this->stats['statement_hits']++;
            return $this->statementCache[$key];
        }

        $this->stats['statement_misses']++;
        $stmt = $this->connection()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException($this->connection()->lastErrorMsg());
        }
        if ($this->statementCacheSize <= 0) {
            return $stmt;
        }
        if (count($this->statementCache) >= $this->statementCacheSize) {
            $old = array_key_first($this->statementCache);
            if ($old !== null) {
                $this->statementCache[$old]->close();
                unset($this->statementCache[$old]);
            }
        }
        return $this->statementCache[$key] = $stmt;
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

    private function type(mixed $value): int
    {
        return match (true) {
            $value === null => SQLITE3_NULL,
            is_int($value), is_bool($value) => SQLITE3_INTEGER,
            is_float($value) => SQLITE3_FLOAT,
            default => SQLITE3_TEXT,
        };
    }

    private function returnsRows(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'PRAGMA', 'WITH', 'EXPLAIN'], true);
    }
}
