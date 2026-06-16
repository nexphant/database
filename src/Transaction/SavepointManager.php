<?php

namespace Nexphant\Database\Transaction;

use Nexphant\Database\DB;
use Nexphant\Database\Drivers\DriverInterface;

class SavepointManager
{
    private static array $savepoints = [];

    public static function begin(string $name, string $connection = 'default'): void
    {
        $conn = DB::connection($connection);
        $conn->query("SAVEPOINT {$name}", []);
        self::$savepoints[$connection][] = $name;
    }

    public static function release(string $name, string $connection = 'default'): void
    {
        $conn = DB::connection($connection);
        $conn->query("RELEASE SAVEPOINT {$name}", []);
        self::removeSavepoint($connection, $name);
    }

    public static function rollback(string $name, string $connection = 'default'): void
    {
        $conn = DB::connection($connection);
        $conn->query("ROLLBACK TO SAVEPOINT {$name}", []);
        self::removeSavepoint($connection, $name);
    }

    public static function transaction(string $name, callable $callback, string $connection = 'default'): mixed
    {
        self::begin($name, $connection);
        try {
            $result = $callback(DB::connection($connection));
            self::release($name, $connection);
            return $result;
        } catch (\Throwable $e) {
            self::rollback($name, $connection);
            throw $e;
        }
    }

    public static function depth(string $connection = 'default'): int
    {
        return count(self::$savepoints[$connection] ?? []);
    }

    public static function active(string $connection = 'default'): array
    {
        return self::$savepoints[$connection] ?? [];
    }

    public static function clear(string $connection = 'default'): void
    {
        unset(self::$savepoints[$connection]);
    }

    private static function removeSavepoint(string $connection, string $name): void
    {
        if (!isset(self::$savepoints[$connection])) {
            return;
        }
        $idx = array_search($name, self::$savepoints[$connection]);
        if ($idx !== false) {
            // Remove this and all nested savepoints
            self::$savepoints[$connection] = array_slice(self::$savepoints[$connection], 0, $idx);
        }
    }
}
