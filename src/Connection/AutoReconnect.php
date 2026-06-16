<?php

namespace Nexphant\Database\Connection;

use Nexphant\Database\DB;

class AutoReconnect
{
    private static int $maxRetries = 3;
    private static int $retryDelay = 100; // ms
    private static array $retryableErrors = [
        'server has gone away',
        'lost connection',
        'broken pipe',
        'connection reset',
        'connection refused',
        'no connection',
        'decryption failed',
    ];

    public static function configure(int $maxRetries = 3, int $retryDelayMs = 100): void
    {
        self::$maxRetries = max(1, $maxRetries);
        self::$retryDelay = max(0, $retryDelayMs);
    }

    public static function execute(string $connection, callable $operation): mixed
    {
        $attempts = 0;
        while (true) {
            try {
                return $operation(DB::connection($connection));
            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts >= self::$maxRetries || !self::isRetryable($e)) {
                    throw $e;
                }
                if (self::$retryDelay > 0) {
                    usleep(self::$retryDelay * 1000 * $attempts);
                }
                DB::reconnect($connection);
            }
        }
    }

    private static function isRetryable(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        foreach (self::$retryableErrors as $pattern) {
            if (str_contains($msg, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
