<?php

namespace Nexphant\Database\Connection;

use Nexphant\Database\Drivers\DriverInterface;

class HealthChecker
{
    private static array $lastCheck = [];
    private static int $interval = 30;

    public static function configure(int $interval = 30): void
    {
        self::$interval = max(1, $interval);
    }

    public static function check(DriverInterface $conn, string $name = 'default'): bool
    {
        $now = time();
        if (isset(self::$lastCheck[$name]) && ($now - self::$lastCheck[$name]) < self::$interval) {
            return true; // skip if recently checked
        }

        try {
            $conn->query('SELECT 1', []);
            self::$lastCheck[$name] = $now;
            return true;
        } catch (\Throwable) {
            unset(self::$lastCheck[$name]);
            return false;
        }
    }

    public static function checkAndReconnect(string $name = 'default'): bool
    {
        try {
            $conn = \Nexphant\Database\DB::connection($name);
            if (self::check($conn, $name)) {
                return true;
            }
            \Nexphant\Database\DB::reconnect($name);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$lastCheck = [];
    }
}
