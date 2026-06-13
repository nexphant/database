<?php

namespace Nexph\Database\Drivers;

use Nexph\Database\QueryLogger;
use Nexph\Server\Deferred;

class MysqliDriver implements AsyncDriverInterface
{
    private ?\mysqli $db = null;
    private ?object $loop = null;
    private array $config = [];
    private int $slowQueryMs = 100;
    private int $inFlight = 0;
    private array $statementCache = [];
    private int $statementCacheSize = 128;
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
        QueryLogger::setContext($config['driver'] ?? 'mysql', $config['pool_name'] ?? '');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = mysqli_init();
        $this->db->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, (int) ($config['connect_timeout'] ?? 5)));
        foreach (($config['mysqli_options'] ?? []) as $option => $value) {
            $this->db->options((int) $option, $value);
        }
        $this->db->real_connect(
            $config['host'] ?? '127.0.0.1',
            $config['username'] ?? null,
            $config['password'] ?? null,
            $config['database'] ?? '',
            (int) ($config['port'] ?? 3306)
        );
        $this->db->set_charset($config['charset'] ?? 'utf8mb4');
        $this->statementCacheSize = max(0, (int) ($config['statement_cache_size'] ?? 128));
        $this->configureSession($config);
        $this->runAfterConnect($config);
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
        return (string) $this->connection()->insert_id;
    }

    public function nativeConnection(): \mysqli
    {
        return $this->connection();
    }

    public function begin(): void
    {
        $this->connection()->begin_transaction();
        $this->stats['transactions']++;
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollback(): void
    {
        $this->connection()->rollback();
        $this->stats['rollbacks']++;
    }

    public function close(): void
    {
        foreach ($this->statementCache as $entry) {
            $entry['stmt']->close();
        }
        $this->statementCache = [];
        $this->db?->close();
        $this->db = null;
    }

    public function stats(): array
    {
        return $this->stats + [
            'driver' => $this->config['driver'] ?? 'mysql',
            'engine' => 'native',
            'async' => true,
            'connections' => $this->db ? 1 : 0,
            'statements_cached' => count($this->statementCache),
            'statement_cache_size' => $this->statementCacheSize,
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

        if ($params !== []) {
            // MySQL MYSQLI_ASYNC doesn't support params — run prepared in deferred
            $this->loop->defer(fn() => $this->resolve($deferred, fn() => $this->run($sql, $params, $write)));
            return $deferred;
        }

        $db = $this->connection();
        $start = microtime(true);
        $this->inFlight++;
        $db->query($sql, MYSQLI_ASYNC);
        $poll = function () use (&$poll, $db, $sql, $params, $write, $start, $deferred) {
            $read = [$db];
            $error = [$db];
            $reject = [];
            if (mysqli_poll($read, $error, $reject, 0, 0) < 1) {
                $this->loop?->addTimer(0.001, $poll);
                return;
            }
            try {
                $result = $db->reap_async_query();
                $rows = [];
                if ($result instanceof \mysqli_result && $this->returnsRows($sql)) {
                    $rows = $result->fetch_all(MYSQLI_ASSOC);
                    $result->free();
                }
                $durationMs = $this->record($sql, $params, $start, $write);
                $deferred->resolve(new DriverResult($rows, $db->affected_rows, $this->lastInsertId(), $durationMs));
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
            if ($params === []) {
                $result = $this->connection()->query($sql);
                $rows = $result instanceof \mysqli_result && $this->returnsRows($sql) ? $result->fetch_all(MYSQLI_ASSOC) : [];
                if ($result instanceof \mysqli_result) {
                    $result->free();
                }
            } else {
                $stmt = $this->prepareStmt($sql);
                $stmt->reset();
                $values = array_values($params);
                $refs = [];
                foreach ($values as $i => &$value) {
                    $refs[$i] = &$value;
                }
                $stmt->bind_param($this->types($values), ...$refs);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = $res instanceof \mysqli_result && $this->returnsRows($sql) ? $res->fetch_all(MYSQLI_ASSOC) : [];
            }
            $durationMs = $this->record($sql, $params, $start, $write);
            return new DriverResult($rows, $this->connection()->affected_rows, $this->lastInsertId(), $durationMs);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            throw $e;
        }
    }

    private function connection(): \mysqli
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not connected');
        }
        return $this->db;
    }

    private function configureSession(array $config): void
    {
        foreach (($config['init_commands'] ?? $config['mysql_init_commands'] ?? []) as $sql) {
            $this->connection()->query((string) $sql);
        }
        foreach (($config['session'] ?? $config['mysql_session'] ?? []) as $name => $value) {
            $this->connection()->query('SET SESSION ' . $name . ' = ' . $this->sqlValue($value));
        }
    }

    private function sqlValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . $this->connection()->real_escape_string((string) $value) . "'";
    }

    private function runAfterConnect(array $config): void
    {
        if (($config['after_connect'] ?? null) !== null) {
            ($config['after_connect'])($this->connection(), $config);
        }
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

    private function prepareStmt(string $sql): \mysqli_stmt
    {
        $key = sha1($sql);
        if (isset($this->statementCache[$key])) {
            $this->stats['statement_hits']++;
            $this->statementCache[$key]['last_used'] = microtime(true);
            return $this->statementCache[$key]['stmt'];
        }

        $this->stats['statement_misses']++;
        $stmt = $this->connection()->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Prepare failed: ' . $this->connection()->error);
        }

        if ($this->statementCacheSize > 0) {
            if (count($this->statementCache) >= $this->statementCacheSize) {
                // LRU eviction
                $lruKey = null;
                $lruTime = PHP_FLOAT_MAX;
                foreach ($this->statementCache as $k => $entry) {
                    if ($entry['last_used'] < $lruTime) {
                        $lruTime = $entry['last_used'];
                        $lruKey = $k;
                    }
                }
                if ($lruKey !== null) {
                    $this->statementCache[$lruKey]['stmt']->close();
                    unset($this->statementCache[$lruKey]);
                }
            }
            $this->statementCache[$key] = ['stmt' => $stmt, 'last_used' => microtime(true)];
        }

        return $stmt;
    }

    private function types(array $params): string
    {
        return implode('', array_map(fn($v) => match (true) {
            is_int($v), is_bool($v) => 'i',
            is_float($v) => 'd',
            default => 's',
        }, $params));
    }

    private function returnsRows(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);
    }
}
