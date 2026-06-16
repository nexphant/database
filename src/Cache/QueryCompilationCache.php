<?php

namespace nexphant\Database\Cache;

class QueryCompilationCache
{
    private static array $cache = [];
    private static array $accessOrder = [];
    private static int $maxSize = 512;
    private static int $hits = 0;
    private static int $misses = 0;

    public static function configure(int $maxSize = 512): void
    {
        self::$maxSize = max(1, $maxSize);
    }

    public static function get(string $key): ?string
    {
        if (isset(self::$cache[$key])) {
            self::$hits++;
            self::$accessOrder[$key] = microtime(true);
            return self::$cache[$key];
        }
        self::$misses++;
        return null;
    }

    public static function put(string $key, string $sql): void
    {
        if (count(self::$cache) >= self::$maxSize && !isset(self::$cache[$key])) {
            asort(self::$accessOrder);
            $lru = array_key_first(self::$accessOrder);
            if ($lru !== null) {
                unset(self::$cache[$lru], self::$accessOrder[$lru]);
            }
        }
        self::$cache[$key] = $sql;
        self::$accessOrder[$key] = microtime(true);
    }

    public static function has(string $key): bool
    {
        return isset(self::$cache[$key]);
    }

    public static function clear(): void
    {
        self::$cache = [];
        self::$accessOrder = [];
    }

    public static function stats(): array
    {
        return [
            'size' => count(self::$cache),
            'max_size' => self::$maxSize,
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => (self::$hits + self::$misses) > 0
                ? round(self::$hits / (self::$hits + self::$misses) * 100, 2)
                : 0.0,
        ];
    }
}
