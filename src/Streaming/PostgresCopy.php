<?php

namespace Nexphant\Database\Streaming;

use Nexphant\Database\DB;

class PostgresCopy
{
    public static function copyIn(string $table, array $columns, array $rows, string $connection = 'default'): int
    {
        $conn = DB::connection($connection);
        $db = self::getNativeConnection($conn);
        if (!$db || !is_resource($db)) {
            throw new \RuntimeException('PostgreSQL COPY requires native pg connection');
        }

        $cols = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
        $sql = "COPY \"{$table}\" ({$cols}) FROM STDIN WITH (FORMAT csv, NULL '\\N')";

        if (!pg_query($db, $sql)) {
            throw new \RuntimeException('COPY command failed: ' . pg_last_error($db));
        }

        $count = 0;
        foreach ($rows as $row) {
            $line = self::toCsvLine($row, $columns);
            if (!pg_put_line($db, $line . "\n")) {
                throw new \RuntimeException('pg_put_line failed');
            }
            $count++;
        }

        if (!pg_put_line($db, "\\.\n")) {
            throw new \RuntimeException('Failed to send end-of-data marker');
        }

        if (!pg_end_copy($db)) {
            throw new \RuntimeException('pg_end_copy failed: ' . pg_last_error($db));
        }

        return $count;
    }

    public static function copyOut(string $table, array $columns, string $connection = 'default'): \Generator
    {
        $conn = DB::connection($connection);
        $db = self::getNativeConnection($conn);
        if (!$db || !is_resource($db)) {
            throw new \RuntimeException('PostgreSQL COPY requires native pg connection');
        }

        $cols = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
        $sql = "COPY \"{$table}\" ({$cols}) TO STDOUT WITH (FORMAT csv, HEADER false, NULL '\\N')";

        if (!pg_query($db, $sql)) {
            throw new \RuntimeException('COPY OUT failed: ' . pg_last_error($db));
        }

        while (($line = pg_get_line($db)) !== false) {
            if ($line === "\\.\n" || $line === "\\.") {
                break;
            }
            yield str_getcsv(rtrim($line, "\n"));
        }
    }

    private static function toCsvLine(array $row, array $columns): string
    {
        $values = [];
        foreach ($columns as $col) {
            $val = $row[$col] ?? null;
            if ($val === null) {
                $values[] = '\\N';
            } elseif (is_string($val) && (str_contains($val, ',') || str_contains($val, '"') || str_contains($val, "\n"))) {
                $values[] = '"' . str_replace('"', '""', $val) . '"';
            } else {
                $values[] = (string) $val;
            }
        }
        return implode(',', $values);
    }

    private static function getNativeConnection(object $conn): mixed
    {
        $ref = new \ReflectionObject($conn);
        if ($ref->hasProperty('db')) {
            $prop = $ref->getProperty('db');
            $prop->setAccessible(true);
            return $prop->getValue($conn);
        }
        return null;
    }
}
