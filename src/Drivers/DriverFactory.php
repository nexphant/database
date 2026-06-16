<?php

namespace Nexphant\Database\Drivers;

class DriverFactory
{
    public static function make(array $config): DriverInterface
    {
        $driver = strtolower((string) ($config['driver'] ?? 'sqlite'));
        $engine = strtolower((string) ($config['engine'] ?? 'auto'));

        if ($engine !== 'pdo') {
            if ($driver === 'sqlite' && in_array($engine, ['native', 'sqlite3'], true) && class_exists(\SQLite3::class)) {
                return new SqliteDriver();
            }
            if (in_array($driver, ['mysql', 'mariadb'], true) && class_exists(\mysqli::class)) {
                return new MysqliDriver();
            }
            if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true) && function_exists('pg_connect')) {
                return new PgsqlDriver();
            }
        }

        return new PdoDriver();
    }
}
