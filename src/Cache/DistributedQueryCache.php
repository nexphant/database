<?php

namespace Nexph\Database\Cache;

class DistributedQueryCache
{
    private static ?object $driver = null;
    private static string $prefix = 'nexph:qcache:';
    private static float $defaultTtl = 60.0;
    private static int $hits = 0;
    private static int $misses = 0;

    public static function configure(array $config): void
    {
        $type = $config['driver'] ?? 'apcu';
        self::$prefix = $config['prefix'] ?? 'nexph:qcache:';
        self::$defaultTtl = (float) ($config['ttl'] ?? 60.0);

        self::$driver = match ($type) {
            'redis' => new RedisCache($config),
            'apcu' => new ApcuCache(),
            default => new ApcuCache(),
        };
    }

    public static function get(string $sql, array $params = []): ?array
    {
        if (!self::$driver) {
            return null;
        }

        $key = self::key($sql, $params);
        $result = self::$driver->get($key);

        if ($result !== null) {
            self::$hits++;
            return $result;
        }

        self::$misses++;
        return null;
    }

    public static function put(string $sql, array $params, array $result, ?float $ttl = null): void
    {
        if (!self::$driver) {
            return;
        }

        $key = self::key($sql, $params);
        self::$driver->set($key, $result, $ttl ?? self::$defaultTtl);
    }

    public static function invalidate(string $pattern = '*'): int
    {
        if (!self::$driver) {
            return 0;
        }
        return self::$driver->deletePattern(self::$prefix . $pattern);
    }

    public static function invalidateTable(string $table): int
    {
        return self::invalidate("*{$table}*");
    }

    public static function flush(): void
    {
        self::$driver?->flush(self::$prefix);
    }

    public static function stats(): array
    {
        return [
            'driver' => self::$driver ? get_class(self::$driver) : 'none',
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => (self::$hits + self::$misses) > 0
                ? round(self::$hits / (self::$hits + self::$misses) * 100, 2)
                : 0.0,
            'prefix' => self::$prefix,
            'default_ttl' => self::$defaultTtl,
        ];
    }

    private static function key(string $sql, array $params): string
    {
        return self::$prefix . sha1($sql . serialize($params));
    }
}
