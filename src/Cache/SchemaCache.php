<?php

namespace Nexph\Database\Cache;

class SchemaCache
{
    private static array $columns = [];
    private static array $indexes = [];
    private static array $tables = [];
    private static ?float $ttl = null;
    private static array $timestamps = [];

    public static function configure(?float $ttl = 60.0): void
    {
        self::$ttl = $ttl;
    }

    public static function columns(string $table, callable $loader): array
    {
        if (self::isValid("col:{$table}")) {
            return self::$columns[$table];
        }
        self::$columns[$table] = $loader();
        self::$timestamps["col:{$table}"] = microtime(true);
        return self::$columns[$table];
    }

    public static function indexes(string $table, callable $loader): array
    {
        if (self::isValid("idx:{$table}")) {
            return self::$indexes[$table];
        }
        self::$indexes[$table] = $loader();
        self::$timestamps["idx:{$table}"] = microtime(true);
        return self::$indexes[$table];
    }

    public static function tables(callable $loader): array
    {
        if (self::isValid('tables')) {
            return self::$tables;
        }
        self::$tables = $loader();
        self::$timestamps['tables'] = microtime(true);
        return self::$tables;
    }

    public static function invalidate(?string $table = null): void
    {
        if ($table === null) {
            self::$columns = [];
            self::$indexes = [];
            self::$tables = [];
            self::$timestamps = [];
            return;
        }
        unset(
            self::$columns[$table],
            self::$indexes[$table],
            self::$timestamps["col:{$table}"],
            self::$timestamps["idx:{$table}"]
        );
    }

    public static function stats(): array
    {
        return [
            'tables_cached' => count(self::$tables),
            'columns_cached' => count(self::$columns),
            'indexes_cached' => count(self::$indexes),
            'ttl' => self::$ttl,
        ];
    }

    private static function isValid(string $key): bool
    {
        if (!isset(self::$timestamps[$key])) {
            return false;
        }
        if (self::$ttl === null) {
            return true;
        }
        return (microtime(true) - self::$timestamps[$key]) < self::$ttl;
    }
}
