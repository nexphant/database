<?php

namespace Nexphant\Database;

use Fiber;
use Nexphant\Database\Drivers\DriverResult;
use Nexphant\Runtime\Runtime;
use Throwable;

final class QueryCoalescer
{
    private static bool $enabled = false;
    private static float $defaultTtl = 0.0;
    private static float $waitTimeoutMs = 5000.0;
    private static array $inFlight = [];
    private static array $cache = [];
    private static int $maxCacheEntries = 1000;
    private static array $stats = [
        'coalesced' => 0,
        'db_hits' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'in_flight' => 0,
        'bypassed' => 0,
    ];

    public static function enabled(?bool $enabled = null): bool
    {
        if ($enabled !== null) {
            self::$enabled = $enabled;
        }
        return self::$enabled;
    }

    public static function cacheTtl(?float $seconds = null): float
    {
        if ($seconds !== null) {
            self::$defaultTtl = max(0.0, $seconds);
        }
        return self::$defaultTtl;
    }

    public static function run(
        string $sql,
        array $params,
        string $connection,
        callable $executor,
        ?float $ttl = null,
        bool $force = false
    ): DriverResult {
        if (!self::isSafeRead($sql)) {
            self::$stats['bypassed']++;
            return $executor();
        }

        $ttl ??= self::$defaultTtl;
        $active = $force || self::$enabled || $ttl > 0.0;
        if (!$active) {
            self::$stats['bypassed']++;
            return $executor();
        }

        $key = self::key($sql, $params, $connection);
        $cached = self::getCached($key);
        if ($cached !== null) {
            self::$stats['cache_hits']++;
            return $cached;
        }
        self::$stats['cache_misses']++;

        if (isset(self::$inFlight[$key])) {
            self::$stats['coalesced']++;
            return self::wait($key);
        }

        self::$inFlight[$key] = ['done' => false, 'result' => null, 'error' => null, 'waiters' => 0];
        self::$stats['in_flight'] = count(self::$inFlight);

        try {
            $result = $executor();
            self::$stats['db_hits']++;
            self::$inFlight[$key]['result'] = self::copy($result);
            if ($ttl > 0.0) {
                self::putCached($key, $result, $ttl);
            }
            return $result;
        } catch (Throwable $e) {
            self::$inFlight[$key]['error'] = $e;
            self::$inFlight[$key]['done'] = true;
            throw $e;
        } finally {
            if (isset(self::$inFlight[$key])) {
                self::$inFlight[$key]['done'] = true;
                if (self::$inFlight[$key]['waiters'] <= 0) {
                    unset(self::$inFlight[$key]);
                }
            }
            self::$stats['in_flight'] = count(self::$inFlight);
            self::pruneCache();
        }
    }

    public static function isSafeRead(string $sql): bool
    {
        $sql = ltrim(preg_replace('/^\s*(?:--[^\n]*\n|\/\*.*?\*\/\s*)*/s', '', $sql) ?? $sql);
        $verb = strtoupper(strtok($sql, " \t\r\n(") ?: '');
        if (!in_array($verb, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'PRAGMA', 'WITH'], true)) {
            return false;
        }
        return !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|UPSERT|ALTER|DROP|CREATE|TRUNCATE|MERGE|GRANT|REVOKE|BEGIN|COMMIT|ROLLBACK)\b/i', $sql);
    }

    public static function stats(): array
    {
        self::pruneCache();
        return self::$stats + [
            'cache_entries' => count(self::$cache),
            'enabled' => self::$enabled,
            'cache_ttl' => self::$defaultTtl,
        ];
    }

    public static function flush(): void
    {
        self::$inFlight = [];
        self::$cache = [];
        self::$stats['in_flight'] = 0;
        if (function_exists('apcu_inc')) {
            apcu_inc('nexphant:query:version', 1, $ok, 1);
        }
    }

    private static function wait(string $key): DriverResult
    {
        $start = microtime(true);
        if (isset(self::$inFlight[$key])) {
            self::$inFlight[$key]['waiters']++;
        }
        while (isset(self::$inFlight[$key]) && !self::$inFlight[$key]['done']) {
            if ((microtime(true) - $start) * 1000 > self::$waitTimeoutMs) {
                self::releaseWaiter($key);
                throw new \RuntimeException('Coalesced query wait timeout');
            }
            if (Fiber::getCurrent() !== null && Runtime::available()) {
                Runtime::yield();
            } else {
                usleep(1000);
            }
        }

        $flight = self::$inFlight[$key] ?? null;
        if ($flight !== null && $flight['error'] instanceof Throwable) {
            self::releaseWaiter($key);
            throw $flight['error'];
        }
        if ($flight !== null && $flight['result'] instanceof DriverResult) {
            $result = self::copy($flight['result']);
            self::releaseWaiter($key);
            return $result;
        }

        self::releaseWaiter($key);
        throw new \RuntimeException('Coalesced query result unavailable');
    }

    private static function releaseWaiter(string $key): void
    {
        if (!isset(self::$inFlight[$key])) {
            return;
        }
        self::$inFlight[$key]['waiters']--;
        if (self::$inFlight[$key]['done'] && self::$inFlight[$key]['waiters'] <= 0) {
            unset(self::$inFlight[$key]);
            self::$stats['in_flight'] = count(self::$inFlight);
        }
    }

    private static function key(string $sql, array $params, string $connection): string
    {
        return sha1(self::cacheVersion() . "\0" . $connection . "\0" . self::normalizeSql($sql) . "\0" . serialize($params));
    }

    private static function normalizeSql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
    }

    private static function getCached(string $key): ?DriverResult
    {
        if (!isset(self::$cache[$key])) {
            if (!function_exists('apcu_fetch')) {
                return null;
            }
            $cached = apcu_fetch('nexphant:query:' . $key, $hit);
            if (!$hit || !$cached instanceof DriverResult) {
                return null;
            }
            self::$cache[$key] = [
                'expires' => microtime(true) + 0.001,
                'result' => self::copy($cached),
            ];
            return self::copy($cached);
        }
        if (self::$cache[$key]['expires'] < microtime(true)) {
            unset(self::$cache[$key]);
            return null;
        }
        return self::copy(self::$cache[$key]['result']);
    }

    private static function putCached(string $key, DriverResult $result, float $ttl): void
    {
        $copy = self::copy($result);
        self::$cache[$key] = [
            'expires' => microtime(true) + $ttl,
            'result' => $copy,
        ];
        if (function_exists('apcu_store')) {
            apcu_store('nexphant:query:' . $key, $copy, (int) max(1, ceil($ttl)));
        }
    }

    private static function cacheVersion(): int
    {
        if (!function_exists('apcu_fetch')) {
            return 1;
        }
        return (int) (apcu_fetch('nexphant:query:version') ?: 1);
    }

    private static function pruneCache(): void
    {
        $now = microtime(true);
        foreach (self::$cache as $key => $entry) {
            if ($entry['expires'] < $now) {
                unset(self::$cache[$key]);
            }
        }
        
        if (count(self::$cache) > self::$maxCacheEntries) {
            uasort(self::$cache, fn($a, $b) => $b['expires'] <=> $a['expires']);
            self::$cache = array_slice(self::$cache, 0, self::$maxCacheEntries, true);
        }
    }

    private static function copy(DriverResult $result): DriverResult
    {
        return new DriverResult($result->rows, $result->affectedRows, $result->insertId, $result->durationMs);
    }
}
