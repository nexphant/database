<?php

namespace Nexph\Database\Pool;

use Fiber;
use Nexph\Database\Drivers\DriverInterface;

class FiberConnectionContext
{
    private static ?\WeakMap $fiberConnections = null;
    private static array $defaultConnections = [];

    private static function map(): \WeakMap
    {
        self::$fiberConnections ??= new \WeakMap();
        return self::$fiberConnections;
    }

    public static function set(string $name, DriverInterface $conn): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber) {
            $map = self::map();
            if (!isset($map[$fiber])) {
                $map[$fiber] = [];
            }
            $data = $map[$fiber];
            $data[$name] = $conn;
            $map[$fiber] = $data;
        } else {
            self::$defaultConnections[$name] = $conn;
        }
    }

    public static function get(string $name): ?DriverInterface
    {
        $fiber = Fiber::getCurrent();
        if ($fiber) {
            $map = self::map();
            $data = $map[$fiber] ?? [];
            return $data[$name] ?? null;
        }
        return self::$defaultConnections[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return self::get($name) !== null;
    }

    public static function release(string $name): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber) {
            $map = self::map();
            if (isset($map[$fiber])) {
                $data = $map[$fiber];
                unset($data[$name]);
                $map[$fiber] = $data;
            }
        } else {
            unset(self::$defaultConnections[$name]);
        }
    }

    public static function releaseAll(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber) {
            $map = self::map();
            if (isset($map[$fiber])) {
                unset($map[$fiber]);
            }
        } else {
            self::$defaultConnections = [];
        }
    }

    public static function withConnection(string $name, DriverInterface $conn, callable $callback): mixed
    {
        self::set($name, $conn);
        try {
            return $callback($conn);
        } finally {
            self::release($name);
        }
    }
}
