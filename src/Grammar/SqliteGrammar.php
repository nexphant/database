<?php

namespace Nexphant\Database\Grammar;

class SqliteGrammar extends BaseGrammar
{
    public function quoteIdentifier(string $name): string
    {
        if ($name === '*') return '*';
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function parameterPlaceholder(int $index): string
    {
        return '?';
    }

    public function supportsReturning(): bool
    {
        return true; // SQLite 3.35+
    }

    public function compileInsert(string $table, array $columns): string
    {
        $cols = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        return "INSERT INTO {$this->quoteIdentifier($table)} ({$cols}) VALUES ({$placeholders})";
    }

    public function compileInsertReturning(string $table, array $columns, string $returning = 'id'): string
    {
        return $this->compileInsert($table, $columns) . ' RETURNING ' . $this->quoteIdentifier($returning);
    }

    public function compileBulkInsert(string $table, array $columns, int $rowCount): string
    {
        $cols = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns));
        $row = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $rows = implode(', ', array_fill(0, $rowCount, $row));
        return "INSERT INTO {$this->quoteIdentifier($table)} ({$cols}) VALUES {$rows}";
    }

    public function compileUpdate(string $table, array $columns): string
    {
        $set = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c) . ' = ?', $columns));
        return "UPDATE {$this->quoteIdentifier($table)} SET {$set}";
    }

    public function compileBatchUpdate(string $table, array $columns, string $keyColumn): string
    {
        $cases = [];
        foreach ($columns as $col) {
            if ($col === $keyColumn) continue;
            $cases[] = $this->quoteIdentifier($col) . " = CASE " . $this->quoteIdentifier($keyColumn)
                . " WHEN ? THEN ? END";
        }
        return "UPDATE {$this->quoteIdentifier($table)} SET " . implode(', ', $cases)
            . " WHERE {$this->quoteIdentifier($keyColumn)} IN (?)";
    }

    public function compileExplain(string $sql): string
    {
        return "EXPLAIN QUERY PLAN {$sql}";
    }
}
