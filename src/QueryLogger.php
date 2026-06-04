<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Database;

class QueryLogger {
    private static array $queries = [];
    private static bool $enabled = false;
    private static string $logFile = '';

    public static function enable(): void {
        self::$enabled = true;
        self::$logFile = sys_get_temp_dir() . '/nexph_queries_' . getmypid() . '.json';
    }

    public static function disable(): void {
        self::$enabled = false;
    }

    private static string $currentDriver = 'unknown';
    private static string $currentPool = '';

    public static function setContext(string $driver, string $pool = ''): void {
        self::$currentDriver = $driver;
        self::$currentPool = $pool;
    }

    private static bool $metricsEnabled = false;

    public static function enableMetrics(): void {
        self::$metricsEnabled = true;
    }

    public static function disableMetrics(): void {
        self::$metricsEnabled = false;
    }

    public static function log(string $sql, array $params, float $time): void {
        if (self::$metricsEnabled) {
            $durationMs = $time * 1000;
            $write = !self::returnsRows($sql);
            Metrics::recordQuery(self::$currentDriver, self::$currentPool, $sql, $durationMs, $write);
        }

        if (!self::$enabled) return;
        
        $entry = [
            'sql' => $sql,
            'params' => $params,
            'time' => $time,
            'timestamp' => microtime(true),
            'driver' => self::$currentDriver,
            'pool' => self::$currentPool,
        ];
        
        self::$queries[] = $entry;
        
        if (self::$logFile) {
            $existing = [];
            if (file_exists(self::$logFile)) {
                $existing = json_decode(file_get_contents(self::$logFile), true) ?: [];
            }
            $existing[] = $entry;
            file_put_contents(self::$logFile, json_encode($existing));
        }
    }

    private static function returnsRows(string $sql): bool {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['SELECT', 'WITH', 'EXPLAIN', 'SHOW', 'DESCRIBE', 'PRAGMA'], true);
    }

    public static function getQueries(): array {
        if (self::$logFile && file_exists(self::$logFile)) {
            return json_decode(file_get_contents(self::$logFile), true) ?: [];
        }
        return self::$queries;
    }

    public static function getTotalTime(): float {
        return array_sum(array_column(self::getQueries(), 'time'));
    }

    public static function getCount(): int {
        return count(self::getQueries());
    }

    public static function clear(): void {
        self::$queries = [];
        if (self::$logFile && file_exists(self::$logFile)) {
            unlink(self::$logFile);
        }
    }
}
