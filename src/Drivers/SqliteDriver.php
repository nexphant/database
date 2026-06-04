<?php

namespace Nexph\Database\Drivers;

use Nexph\Database\QueryLogger;
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
        $this->db->busyTimeout(max(1, (int) ($config['busy_timeout_ms'] ?? 5000)));
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA temp_store = MEMORY');
        $this->db->exec('PRAGMA cache_size = ' . (int) ($config['cache_size'] ?? -20000));
        $this->db->exec('PRAGMA mmap_size = ' . (int) ($config['mmap_size'] ?? 268435456));
        $this->db->exec('PRAGMA journal_size_limit = ' . (int) ($config['journal_size_limit'] ?? 67108864));
        $this->db->exec('PRAGMA optimize');
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
