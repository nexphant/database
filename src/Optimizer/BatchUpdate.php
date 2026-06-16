<?php

namespace nexphant\Database\Optimizer;

use nexphant\Database\DB;
use nexphant\Database\Grammar\DriverGrammar;

class BatchUpdate
{
    private string $table;
    private string $keyColumn = 'id';
    private array $rows = [];
    private int $chunkSize = 500;
    private string $connection = 'default';
    private ?DriverGrammar $grammar = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function connection(string $name): static
    {
        $this->connection = $name;
        return $this;
    }

    public function grammar(DriverGrammar $grammar): static
    {
        $this->grammar = $grammar;
        return $this;
    }

    public function key(string $column): static
    {
        $this->keyColumn = $column;
        return $this;
    }

    public function chunkSize(int $size): static
    {
        $this->chunkSize = max(1, $size);
        return $this;
    }

    public function rows(array $rows): static
    {
        $this->rows = $rows;
        return $this;
    }

    public function addRow(array $row): static
    {
        $this->rows[] = $row;
        return $this;
    }

    public function execute(): int
    {
        if (empty($this->rows)) {
            return 0;
        }

        $total = 0;
        $chunks = array_chunk($this->rows, $this->chunkSize);

        foreach ($chunks as $chunk) {
            $total += $this->updateChunk($chunk);
        }

        return $total;
    }

    public function executeInTransaction(): int
    {
        return DB::transaction(function () {
            return $this->execute();
        }, $this->connection);
    }

    private function updateChunk(array $chunk): int
    {
        $columns = array_keys($chunk[0]);
        $updateCols = array_filter($columns, fn($c) => $c !== $this->keyColumn);

        if (empty($updateCols)) {
            return 0;
        }

        $cases = [];
        $bindings = [];
        $ids = [];

        foreach ($updateCols as $col) {
            $when = [];
            foreach ($chunk as $row) {
                $when[] = "WHEN ? THEN ?";
                $bindings[] = $row[$this->keyColumn];
                $bindings[] = $row[$col];
                $ids[$row[$this->keyColumn]] = true;
            }
            $cases[] = "`{$col}` = CASE `{$this->keyColumn}` " . implode(' ', $when) . " ELSE `{$col}` END";
        }

        $idList = array_keys($ids);
        $placeholders = implode(', ', array_fill(0, count($idList), '?'));
        $bindings = array_merge($bindings, $idList);

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $cases)
            . " WHERE `{$this->keyColumn}` IN ({$placeholders})";

        $result = DB::result($sql, $bindings, $this->connection);
        return $result->affectedRows;
    }
}
