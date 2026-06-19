<?php

namespace Nexphant\Database;

use Nexphant\Database\Drivers\DriverFactory;
use Nexphant\Database\Drivers\DriverInterface;
use Nexphant\Database\Drivers\DriverResult;
use Nexphant\Database\Drivers\PdoDriver;
use Nexphant\Database\Pool\PoolManager;

class DB
{
    private static array $drivers = [];
    private static array $configs = [];
    private static bool $poolEnabled = false;

    public static function connect(array $config, string $name = 'default'): DriverInterface
    {
        if (isset(self::$drivers[$name])) {
            return self::$drivers[$name];
        }

        $driver = DriverFactory::make($config);
        $driver->connect($config);
        self::$drivers[$name] = $driver;
        self::$configs[$name] = $config;
        return $driver;
    }

    public static function configurePool(string $name, array $config): void
    {
        PoolManager::configure($name, $config);
        self::$poolEnabled = true;
    }

    public static function pool(string $name = 'default'): DriverInterface
    {
        return PoolManager::get($name);
    }

    public static function releaseToPool(DriverInterface $conn, string $name = 'default'): void
    {
        PoolManager::release($conn, $name);
    }

    public static function withPool(string $name, callable $callback): mixed
    {
        $conn = PoolManager::get($name);
        try {
            return $callback($conn);
        } finally {
            PoolManager::release($conn, $name);
        }
    }

    public static function connection(string $name = 'default'): DriverInterface
    {
        if (self::$poolEnabled && PoolManager::has($name)) {
            return PoolManager::get($name);
        }
        if (!isset(self::$drivers[$name])) {
            throw new \RuntimeException("Connection [{$name}] not initialized");
        }
        return self::$drivers[$name];
    }

    public static function reconnect(?string $name = null): void
    {
        if ($name === null) {
            foreach (self::$drivers as $driver) {
                $driver->close();
            }
            $configs = self::$configs;
            self::$drivers = [];
            foreach ($configs as $n => $config) {
                self::connect($config, $n);
            }
            return;
        }
        if (isset(self::$drivers[$name])) {
            self::$drivers[$name]->close();
            unset(self::$drivers[$name]);
        }
        if (isset(self::$configs[$name])) {
            self::connect(self::$configs[$name], $name);
        }
    }

    public static function disconnect(?string $name = null): void
    {
        if ($name === null) {
            foreach (self::$drivers as $driver) {
                $driver->close();
            }
            self::$drivers = [];
            self::$configs = [];
            return;
        }

        if (isset(self::$drivers[$name])) {
            self::$drivers[$name]->close();
        }
        unset(self::$drivers[$name], self::$configs[$name]);
    }

    public static function query(string $sql, array $params = [], string $connection = 'default'): array
    {
        return self::result($sql, $params, $connection)->rows;
    }

    public static function execute(string $sql, array $params = [], string $connection = 'default'): bool
    {
        self::connection($connection)->execute($sql, $params);
        return true;
    }

    public static function exec(string|array|callable $command, array $params = [], string $connection = 'default'): mixed
    {
        $driver = self::connection($connection);
        if (is_callable($command)) {
            return $command($driver->nativeConnection(), $driver);
        }
        if (is_array($command)) {
            $results = [];
            foreach ($command as $sql => $bindings) {
                if (is_int($sql)) {
                    $results[] = $driver->execute((string) $bindings);
                    continue;
                }
                $results[] = $driver->execute((string) $sql, is_array($bindings) ? $bindings : []);
            }
            return $results;
        }
        return $driver->execute($command, $params);
    }

    public static function native(string $connection = 'default'): mixed
    {
        return self::connection($connection)->nativeConnection();
    }

    public static function withNative(callable $callback, string $connection = 'default'): mixed
    {
        $driver = self::connection($connection);
        return $callback($driver->nativeConnection(), $driver);
    }

    public static function result(string $sql, array $params = [], string $connection = 'default'): DriverResult
    {
        $driver = self::connection($connection);
        if (!self::returnsRows($sql)) {
            return $driver->execute($sql, $params);
        }
        return QueryCoalescer::run($sql, $params, $connection, fn() => $driver->query($sql, $params));
    }

    public static function coalescedResult(
        string $sql,
        array $params = [],
        string $connection = 'default',
        ?float $ttl = null,
        bool $force = true
    ): DriverResult {
        $driver = self::connection($connection);
        if (!self::returnsRows($sql)) {
            return $driver->execute($sql, $params);
        }
        return QueryCoalescer::run($sql, $params, $connection, fn() => $driver->query($sql, $params), $ttl, $force);
    }

    public static function coalescedQuery(
        string $sql,
        array $params = [],
        string $connection = 'default',
        ?float $ttl = null,
        bool $force = true
    ): array {
        return self::coalescedResult($sql, $params, $connection, $ttl, $force)->rows;
    }

    public static function coalesce(?bool $enabled = null): bool
    {
        return QueryCoalescer::enabled($enabled);
    }

    public static function cache(?float $seconds = null): float
    {
        return QueryCoalescer::cacheTtl($seconds);
    }

    public static function flushCoalescing(): void
    {
        QueryCoalescer::flush();
    }

    public static function statement(string $sql, array $params = [], string $connection = 'default'): \PDOStatement
    {
        $driver = self::connection($connection);
        if ($driver instanceof PdoDriver) {
            return $driver->statement($sql, $params);
        }
        throw new \RuntimeException('Statements are only available on PDO engine');
    }

    public static function scalar(string $sql, array $params = [], string $connection = 'default'): mixed
    {
        $rows = self::query($sql, $params, $connection);
        if ($rows === []) {
            return null;
        }
        $row = reset($rows);
        return is_array($row) ? reset($row) : null;
    }

    public static function transaction(callable $callback, string $connection = 'default'): mixed
    {
        $driver = self::connection($connection);
        $driver->begin();
        try {
            $result = $callback($driver);
            $driver->commit();
            return $result;
        } catch (\Throwable $e) {
            $driver->rollback();
            throw $e;
        }
    }

    public static function lastInsertId(string $connection = 'default'): string
    {
        return self::connection($connection)->lastInsertId();
    }

    public static function table(string $table, string $connection = 'default'): QueryBuilder
    {
        return (new QueryBuilder($table))->connection($connection);
    }

    public static function raw(string $sql, array $params = [], string $connection = 'default'): mixed
    {
        return self::query($sql, $params, $connection);
    }

    public static function schema(string $connection = 'default'): Schema
    {
        return new Schema($connection);
    }

    public static function poolStats(?string $name = null): array
    {
        return PoolManager::stats($name);
    }

    public static function stats(?string $connection = null): array
    {
        if ($connection !== null) {
            return self::connection($connection)->stats();
        }

        $stats = [
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
            'connections' => count(self::$drivers),
            'statements_cached' => 0,
            'statement_cache_size' => 0,
            'slow_query_ms' => 100,
            'avg_ms' => 0.0,
            'driver' => 'none',
            'engine' => 'none',
            'async' => false,
        ];

        foreach (self::$drivers as $driver) {
            $next = $driver->stats();
            foreach (['queries', 'writes', 'errors', 'slow_queries', 'statement_hits', 'statement_misses', 'transactions', 'rollbacks', 'statements_cached'] as $key) {
                $stats[$key] += (int) ($next[$key] ?? 0);
            }
            $stats['total_ms'] += (float) ($next['total_ms'] ?? 0.0);
            $stats['max_ms'] = max((float) $stats['max_ms'], (float) ($next['max_ms'] ?? 0));
            $stats['statement_cache_size'] = max((int) $stats['statement_cache_size'], (int) ($next['statement_cache_size'] ?? 0));
            $stats['slow_query_ms'] = max((int) $stats['slow_query_ms'], (int) ($next['slow_query_ms'] ?? 0));
            $stats['driver'] = (string) ($next['driver'] ?? $stats['driver']);
            $stats['engine'] = (string) ($next['engine'] ?? $stats['engine']);
            $stats['async'] = $stats['async'] || (bool) ($next['async'] ?? false);
        }

        $stats['avg_ms'] = $stats['queries'] > 0 ? $stats['total_ms'] / $stats['queries'] : 0.0;
        return $stats + QueryCoalescer::stats();
    }

    private static function returnsRows(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'PRAGMA', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE'], true);
    }
}
