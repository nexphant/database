<?php

namespace Nexph\Database\Drivers;

use Nexph\Database\QueryLogger;
use Nexph\Server\Deferred;

class PgsqlDriver implements AsyncDriverInterface
{
    /** @var resource|null */
    private $db = null;
    private ?object $loop = null;
    private array $config = [];
    private int $slowQueryMs = 100;
    private int $inFlight = 0;
    private array $preparedCache = [];
    private int $preparedCacheSize = 128;
    private int $preparedSeq = 0;
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
        $this->slowQueryMs = max(1, (int) ($config['slow_query_ms'] ?? 100));
        QueryLogger::setContext('pgsql', $config['pool_name'] ?? '');
        $parts = [
            'host=' . ($config['host'] ?? '127.0.0.1'),
            'port=' . (int) ($config['port'] ?? 5432),
            'dbname=' . ($config['database'] ?? ''),
        ];
        if (isset($config['username'])) {
            $parts[] = 'user=' . $config['username'];
        }
        if (isset($config['password'])) {
            $parts[] = 'password=' . $config['password'];
        }
        $this->db = pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
        if (!$this->db) {
            throw new \RuntimeException('PostgreSQL connection failed');
        }
        $this->preparedCacheSize = max(0, (int) ($config['statement_cache_size'] ?? 128));
    }

    public function attachLoop(object $loop): void
    {
        $this->loop = $loop;
    }

    public function query(string $sql, array $params = []): DriverResult
    {
        return $this->run($sql, $params, false);
    }

    public function execute(string $sql, array $params = []): DriverResult
    {
        return $this->run($sql, $params, true);
    }

    public function queryAsync(string $sql, array $params = []): \Generator
    {
        return yield $this->async($sql, $params, false);
    }

    public function executeAsync(string $sql, array $params = []): \Generator
    {
        return yield $this->async($sql, $params, true);
    }

    public function inFlight(): int
    {
        return $this->inFlight;
    }

    public function lastInsertId(): string
    {
        return $this->tryLastInsertId() ?? '';
    }

    public function begin(): void
    {
        pg_query($this->connection(), 'BEGIN');
        $this->stats['transactions']++;
    }

    public function commit(): void
    {
        pg_query($this->connection(), 'COMMIT');
    }

    public function rollback(): void
    {
        pg_query($this->connection(), 'ROLLBACK');
        $this->stats['rollbacks']++;
    }

    public function close(): void
    {
        $this->preparedCache = [];
        $this->preparedSeq = 0;
        if ($this->db) {
            pg_close($this->db);
        }
        $this->db = null;
    }

    public function stats(): array
    {
        return $this->stats + [
            'driver' => 'pgsql',
            'engine' => 'native',
            'async' => true,
            'connections' => $this->db ? 1 : 0,
            'statements_cached' => count($this->preparedCache),
            'statement_cache_size' => $this->preparedCacheSize,
            'slow_query_ms' => $this->slowQueryMs,
            'avg_ms' => $this->stats['queries'] > 0 ? $this->stats['total_ms'] / $this->stats['queries'] : 0.0,
            'in_flight' => $this->inFlight,
        ];
    }

    private function async(string $sql, array $params, bool $write): Deferred
    {
        $deferred = new Deferred();
        if (!$this->loop) {
            $this->resolve($deferred, fn() => $this->run($sql, $params, $write));
            return $deferred;
        }

        $db = $this->connection();
        $start = microtime(true);
        $this->inFlight++;

        // Convert backtick quoting to double-quote for PG
        $pgSql = preg_replace('/`([^`]+)`/', '"$1"', $sql) ?? $sql;

        if ($params === []) {
            $sent = pg_send_query($db, $pgSql);
        } else {
            // Convert ? placeholders to $1, $2... for pg_send_query_params
            $i = 0;
            $paramSql = preg_replace_callback('/\?/', function () use (&$i) {
                return '$' . ++$i;
            }, $pgSql) ?? $pgSql;
            $sent = pg_send_query_params($db, $paramSql, array_values($params));
        }

        if (!$sent) {
            $this->inFlight--;
            $deferred->resolve(['error' => pg_last_error($db)]);
            return $deferred;
        }
        $poll = function () use (&$poll, $db, $sql, $params, $write, $start, $deferred) {
            if (pg_connection_busy($db)) {
                $this->loop?->addTimer(0.001, $poll);
                return;
            }
            try {
                $result = pg_get_result($db);
                $rows = $result && $this->returnsRows($sql) ? pg_fetch_all($result) ?: [] : [];
                $affected = $result ? pg_affected_rows($result) : 0;
                $insertId = null;
                if ($write && $this->returnsRows($sql)) {
                    $insertId = $rows[0]['id'] ?? $this->tryLastInsertId();
                } elseif ($write) {
                    $insertId = $this->tryLastInsertId();
                }
                $durationMs = $this->record($sql, $params, $start, $write);
                $deferred->resolve(new DriverResult($rows, $affected, $insertId, $durationMs));
            } catch (\Throwable $e) {
                $this->stats['errors']++;
                $deferred->resolve(['error' => $e->getMessage()]);
            } finally {
                $this->inFlight--;
            }
        };
        $this->loop->addTimer(0.0, $poll);
        return $deferred;
    }

    private function resolve(Deferred $deferred, callable $fn): void
    {
        try {
            $deferred->resolve($fn());
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $deferred->resolve(['error' => $e->getMessage()]);
        }
    }

    private function run(string $sql, array $params, bool $write): DriverResult
    {
        $start = microtime(true);
        try {
            $wireSql = $this->sql($sql, $params);
            if ($params === []) {
                $result = pg_query($this->connection(), $wireSql);
            } else {
                $stmtName = $this->getPrepared($wireSql);
                $result = pg_execute($this->connection(), $stmtName, array_values($params));
            }
            if (!$result) {
                throw new \RuntimeException(pg_last_error($this->connection()));
            }
            $rows = $this->returnsRows($sql) ? pg_fetch_all($result) ?: [] : [];
            $insertId = null;
            if ($write && $this->returnsRows($sql)) {
                // RETURNING clause — extract id from rows
                $insertId = $rows[0]['id'] ?? $this->tryLastInsertId();
            } elseif ($write) {
                $insertId = $this->tryLastInsertId();
            }
            $durationMs = $this->record($sql, $params, $start, $write);
            return new DriverResult($rows, pg_affected_rows($result), $insertId, $durationMs);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }

    private function getPrepared(string $sql): string
    {
        $key = sha1($sql);
        if (isset($this->preparedCache[$key])) {
            $this->stats['statement_hits']++;
            $this->preparedCache[$key]['last_used'] = microtime(true);
            return $this->preparedCache[$key]['name'];
        }

        $this->stats['statement_misses']++;
        $name = 'nexph_stmt_' . (++$this->preparedSeq);
        $result = pg_prepare($this->connection(), $name, $sql);
        if (!$result) {
            throw new \RuntimeException('pg_prepare failed: ' . pg_last_error($this->connection()));
        }

        if ($this->preparedCacheSize > 0) {
            if (count($this->preparedCache) >= $this->preparedCacheSize) {
                // LRU eviction
                $lruKey = null;
                $lruTime = PHP_FLOAT_MAX;
                foreach ($this->preparedCache as $k => $entry) {
                    if ($entry['last_used'] < $lruTime) {
                        $lruTime = $entry['last_used'];
                        $lruKey = $k;
                    }
                }
                if ($lruKey !== null) {
                    @pg_query($this->connection(), "DEALLOCATE {$this->preparedCache[$lruKey]['name']}");
                    unset($this->preparedCache[$lruKey]);
                }
            }
            $this->preparedCache[$key] = ['name' => $name, 'last_used' => microtime(true)];
        }

        return $name;
    }

    /** @return resource */
    private function connection()
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not connected');
        }
        return $this->db;
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

    private function returnsRows(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);
    }

    private function sql(string $sql, array $params): string
    {
        $sql = preg_replace('/`([^`]+)`/', '"$1"', $sql) ?? $sql;
        if ($params === []) {
            return $sql;
        }
        $i = 0;
        return preg_replace_callback('/\?/', function () use (&$i) {
            return '$' . ++$i;
        }, $sql) ?? $sql;
    }

    private function tryLastInsertId(): ?string
    {
        $result = @pg_query($this->connection(), 'SELECT LASTVAL()');
        if (!$result) {
            return null;
        }
        $value = pg_fetch_result($result, 0, 0);
        return $value === false ? null : (string) $value;
    }
}
