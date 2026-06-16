<?php

namespace nexphant\Database\Pool;

use nexphant\Database\Drivers\DriverInterface;

class PoolManager
{
    private static array $pools = [];

    public static function configure(string $name, array $config): void
    {
        self::$pools[$name] = new ConnectionPool(
            name: $name,
            config: $config,
            maxSize: (int) ($config['pool_max'] ?? 10),
            minIdle: (int) ($config['pool_min_idle'] ?? 2),
            idleTimeout: (int) ($config['pool_idle_timeout'] ?? 300),
            maxLifetime: (int) ($config['pool_max_lifetime'] ?? 3600),
            healthCheckInterval: (int) ($config['pool_health_interval'] ?? 30),
            acquireTimeout: (float) ($config['pool_acquire_timeout'] ?? 5.0),
            maxWaitQueue: (int) ($config['pool_max_wait_queue'] ?? 50),
        );
    }

    public static function get(string $name = 'default'): DriverInterface
    {
        if (!isset(self::$pools[$name])) {
            throw new \RuntimeException("Pool [{$name}] not configured");
        }
        return self::$pools[$name]->get();
    }

    public static function release(DriverInterface $conn, string $name = 'default'): void
    {
        self::$pools[$name]?->release($conn);
    }

    public static function stats(?string $name = null): array
    {
        if ($name !== null) {
            return self::$pools[$name]?->stats() ?? [];
        }
        return array_map(fn(ConnectionPool $p) => $p->stats(), self::$pools);
    }

    public static function warmup(?string $name = null): void
    {
        if ($name !== null) {
            self::$pools[$name]?->warmup();
            return;
        }
        foreach (self::$pools as $pool) {
            $pool->warmup();
        }
    }

    public static function closeAll(): void
    {
        foreach (self::$pools as $pool) {
            $pool->closeAll();
        }
        self::$pools = [];
    }

    public static function has(string $name): bool
    {
        return isset(self::$pools[$name]);
    }
}
