<?php

namespace Nexphant\Database\Grammar;

class PostgresGrammar extends BaseGrammar
{
    public function quoteIdentifier(string $name): string
    {
        if ($name === '*') return '*';
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function parameterPlaceholder(int $index): string
    {
        return '$' . ($index + 1);
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function compileInsert(string $table, array $columns): string
    {
        $cols = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns));
        $placeholders = implode(', ', array_map(
            fn($i) => $this->parameterPlaceholder($i),
            range(0, count($columns) - 1)
        ));
        return "INSERT INTO {$this->quoteIdentifier($table)} ({$cols}) VALUES ({$placeholders})";
    }

    public function compileInsertReturning(string $table, array $columns, string $returning = 'id'): string
    {
        return $this->compileInsert($table, $columns) . ' RETURNING ' . $this->quoteIdentifier($returning);
    }

    public function compileBulkInsert(string $table, array $columns, int $rowCount): string
    {
        $cols = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns));
        $rows = [];
        $idx = 0;
        for ($r = 0; $r < $rowCount; $r++) {
            $placeholders = [];
            for ($c = 0; $c < count($columns); $c++) {
                $placeholders[] = $this->parameterPlaceholder($idx++);
            }
            $rows[] = '(' . implode(', ', $placeholders) . ')';
        }
        return "INSERT INTO {$this->quoteIdentifier($table)} ({$cols}) VALUES " . implode(', ', $rows);
    }

    public function compileUpdate(string $table, array $columns): string
    {
        $set = [];
        foreach ($columns as $i => $col) {
            $set[] = $this->quoteIdentifier($col) . ' = ' . $this->parameterPlaceholder($i);
        }
        return "UPDATE {$this->quoteIdentifier($table)} SET " . implode(', ', $set);
    }

    public function compileBatchUpdate(string $table, array $columns, string $keyColumn): string
    {
        $cases = [];
        foreach ($columns as $col) {
            if ($col === $keyColumn) continue;
            $cases[] = $this->quoteIdentifier($col) . " = CASE " . $this->quoteIdentifier($keyColumn)
                . " WHEN $1 THEN $2 END";
        }
        return "UPDATE {$this->quoteIdentifier($table)} SET " . implode(', ', $cases)
            . " WHERE {$this->quoteIdentifier($keyColumn)} = ANY($3)";
    }

    public function compileExplain(string $sql): string
    {
        return "EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) {$sql}";
    }

    public function compileLock(?string $lock): string
    {
        return match ($lock) {
            'share' => ' FOR SHARE',
            'update' => ' FOR UPDATE',
            'skip' => ' FOR UPDATE SKIP LOCKED',
            'nowait' => ' FOR UPDATE NOWAIT',
            default => parent::compileLock($lock),
        };
    }

    public function compileCopy(string $table, array $columns): string
    {
        $cols = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns));
        return "COPY {$this->quoteIdentifier($table)} ({$cols}) FROM STDIN WITH (FORMAT csv)";
    }
}
