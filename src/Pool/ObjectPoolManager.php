<?php

namespace Nexphant\Database\Pool;

use Nexphant\Database\Drivers\DriverInterface;

class ObjectPoolManager
{
    private static array $pools = [];

    public static function register(string $class, int $maxSize = 50, ?callable $factory = null, ?callable $reset = null): void
    {
        self::$pools[$class] = [
            'idle' => [],
            'max_size' => $maxSize,
            'factory' => $factory ?? fn() => new $class(),
            'reset' => $reset,
            'created' => 0,
            'reused' => 0,
        ];
    }

    public static function get(string $class): object
    {
        if (!isset(self::$pools[$class])) {
            self::register($class);
        }

        $pool = &self::$pools[$class];

        if (!empty($pool['idle'])) {
            $pool['reused']++;
            $obj = array_pop($pool['idle']);
            if ($pool['reset']) {
                ($pool['reset'])($obj);
            }
            return $obj;
        }

        $pool['created']++;
        return ($pool['factory'])();
    }

    public static function release(string $class, object $obj): void
    {
        if (!isset(self::$pools[$class])) {
            return;
        }

        $pool = &self::$pools[$class];
        if (count($pool['idle']) < $pool['max_size']) {
            $pool['idle'][] = $obj;
        }
    }

    public static function stats(?string $class = null): array
    {
        if ($class !== null) {
            $pool = self::$pools[$class] ?? null;
            if (!$pool) return [];
            return [
                'idle' => count($pool['idle']),
                'max_size' => $pool['max_size'],
                'created' => $pool['created'],
                'reused' => $pool['reused'],
                'reuse_rate' => ($pool['created'] + $pool['reused']) > 0
                    ? round($pool['reused'] / ($pool['created'] + $pool['reused']) * 100, 2)
                    : 0.0,
            ];
        }

        $result = [];
        foreach (self::$pools as $name => $pool) {
            $result[$name] = [
                'idle' => count($pool['idle']),
                'max_size' => $pool['max_size'],
                'created' => $pool['created'],
                'reused' => $pool['reused'],
            ];
        }
        return $result;
    }

    public static function clear(?string $class = null): void
    {
        if ($class !== null) {
            if (isset(self::$pools[$class])) {
                self::$pools[$class]['idle'] = [];
            }
            return;
        }
        foreach (self::$pools as &$pool) {
            $pool['idle'] = [];
        }
    }
}
