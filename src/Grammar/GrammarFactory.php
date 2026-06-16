<?php

namespace nexphant\Database\Grammar;

class GrammarFactory
{
    public static function make(string $driver): DriverGrammar
    {
        return match ($driver) {
            'mysql', 'mariadb' => new MysqlGrammar(),
            'pgsql', 'postgres', 'postgresql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
            default => new SqliteGrammar(),
        };
    }
}
