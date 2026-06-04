<?php

namespace Nexph\Database\Optimizer;

use Nexph\Database\DB;
use Nexph\Database\Grammar\DriverGrammar;

class BulkInsert
{
    private string $table;
    private array $columns = [];
    private array $rows = [];
    private int $chunkSize = 1000;
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

    public function chunkSize(int $size): static
    {
        $this->chunkSize = max(1, $size);
        return $this;
    }

    public function columns(array $columns): static
    {
        $this->columns = $columns;
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

        if (empty($this->columns)) {
            $this->columns = array_keys($this->rows[0]);
        }

        $total = 0;
        $chunks = array_chunk($this->rows, $this->chunkSize);

        foreach ($chunks as $chunk) {
            $total += $this->insertChunk($chunk);
        }

        return $total;
    }

    public function executeInTransaction(): int
    {
        return DB::transaction(function () {
            return $this->execute();
        }, $this->connection);
    }

    private function insertChunk(array $chunk): int
    {
        $rowCount = count($chunk);
        $colCount = count($this->columns);

        if ($this->grammar) {
            $sql = $this->grammar->compileBulkInsert($this->table, $this->columns, $rowCount);
        } else {
            $cols = implode(', ', array_map(fn($c) => "`{$c}`", $this->columns));
            $row = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
            $sql = "INSERT INTO `{$this->table}` ({$cols}) VALUES " . implode(', ', array_fill(0, $rowCount, $row));
        }

        $bindings = [];
        foreach ($chunk as $row) {
            foreach ($this->columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }

        $result = DB::result($sql, $bindings, $this->connection);
        return $result->affectedRows;
    }
}
