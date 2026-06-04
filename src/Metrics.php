<?php

namespace Nexph\Database;

class Metrics
{
    private static array $perDriver = [];
    private static array $perPool = [];
    private static array $slowQueries = [];
    private static int $slowQueryLimit = 100;
    private static float $slowThresholdMs = 100.0;

    public static function configure(float $slowThresholdMs = 100.0, int $slowQueryLimit = 100): void
    {
        self::$slowThresholdMs = max(1.0, $slowThresholdMs);
        self::$slowQueryLimit = max(10, $slowQueryLimit);
    }

    public static function recordQuery(string $driver, string $pool, string $sql, float $durationMs, bool $write): void
    {
        // Per-driver
        if (!isset(self::$perDriver[$driver])) {
            self::$perDriver[$driver] = self::emptyBucket();
        }
        self::increment(self::$perDriver[$driver], $durationMs, $write);

        // Per-pool
        if ($pool !== '') {
            if (!isset(self::$perPool[$pool])) {
                self::$perPool[$pool] = self::emptyBucket();
            }
            self::increment(self::$perPool[$pool], $durationMs, $write);
        }

        // Slow query log
        if ($durationMs >= self::$slowThresholdMs) {
            self::$slowQueries[] = [
                'sql' => mb_substr($sql, 0, 500),
                'driver' => $driver,
                'pool' => $pool,
                'duration_ms' => $durationMs,
                'at' => microtime(true),
            ];
            if (count(self::$slowQueries) > self::$slowQueryLimit) {
                array_shift(self::$slowQueries);
            }
        }
    }

    public static function driverStats(?string $driver = null): array
    {
        if ($driver !== null) {
            return self::$perDriver[$driver] ?? self::emptyBucket();
        }
        return self::$perDriver;
    }

    public static function poolStats(?string $pool = null): array
    {
        if ($pool !== null) {
            return self::$perPool[$pool] ?? self::emptyBucket();
        }
        return self::$perPool;
    }

    public static function slowQueries(): array
    {
        return self::$slowQueries;
    }

    public static function slowQueryAnalysis(): array
    {
        if (empty(self::$slowQueries)) {
            return ['count' => 0, 'patterns' => []];
        }

        // Group by normalized SQL pattern
        $patterns = [];
        foreach (self::$slowQueries as $entry) {
            $pattern = self::normalize($entry['sql']);
            if (!isset($patterns[$pattern])) {
                $patterns[$pattern] = [
                    'pattern' => $pattern,
                    'count' => 0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                    'min_ms' => PHP_FLOAT_MAX,
                    'drivers' => [],
                ];
            }
            $patterns[$pattern]['count']++;
            $patterns[$pattern]['total_ms'] += $entry['duration_ms'];
            $patterns[$pattern]['max_ms'] = max($patterns[$pattern]['max_ms'], $entry['duration_ms']);
            $patterns[$pattern]['min_ms'] = min($patterns[$pattern]['min_ms'], $entry['duration_ms']);
            $patterns[$pattern]['drivers'][$entry['driver']] = true;
        }

        // Sort by total time desc
        usort($patterns, fn($a, $b) => $b['total_ms'] <=> $a['total_ms']);

        foreach ($patterns as &$p) {
            $p['avg_ms'] = $p['count'] > 0 ? round($p['total_ms'] / $p['count'], 2) : 0;
            $p['drivers'] = array_keys($p['drivers']);
        }

        return [
            'count' => count(self::$slowQueries),
            'threshold_ms' => self::$slowThresholdMs,
            'patterns' => array_slice($patterns, 0, 20),
        ];
    }

    public static function reset(): void
    {
        self::$perDriver = [];
        self::$perPool = [];
        self::$slowQueries = [];
    }

    public static function all(): array
    {
        return [
            'drivers' => self::$perDriver,
            'pools' => self::$perPool,
            'slow_queries' => self::slowQueryAnalysis(),
        ];
    }

    private static function increment(array &$bucket, float $durationMs, bool $write): void
    {
        $bucket['queries']++;
        if ($write) $bucket['writes']++;
        $bucket['total_ms'] += $durationMs;
        $bucket['max_ms'] = max($bucket['max_ms'], $durationMs);
        if ($durationMs >= self::$slowThresholdMs) {
            $bucket['slow']++;
        }
    }

    private static function emptyBucket(): array
    {
        return [
            'queries' => 0,
            'writes' => 0,
            'total_ms' => 0.0,
            'max_ms' => 0.0,
            'slow' => 0,
        ];
    }

    private static function normalize(string $sql): string
    {
        // Replace values with ? for grouping
        $sql = preg_replace('/\'[^\']*\'/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return $sql;
    }
}
