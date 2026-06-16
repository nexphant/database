<?php

namespace nexphant\Database\Optimizer;

use nexphant\Database\DB;

class BatchDelete
{
    private string $table;
    private string $column = 'id';
    private array $values = [];
    private int $chunkSize = 1000;
    private string $connection = 'default';

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function connection(string $name): static
    {
        $this->connection = $name;
        return $this;
    }

    public function column(string $column): static
    {
        $this->column = $column;
        return $this;
    }

    public function chunkSize(int $size): static
    {
        $this->chunkSize = max(1, $size);
        return $this;
    }

    public function values(array $values): static
    {
        $this->values = $values;
        return $this;
    }

    public function execute(): int
    {
        if (empty($this->values)) {
            return 0;
        }

        $total = 0;
        $chunks = array_chunk($this->values, $this->chunkSize);

        foreach ($chunks as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $sql = "DELETE FROM `{$this->table}` WHERE `{$this->column}` IN ({$placeholders})";
            $result = DB::result($sql, $chunk, $this->connection);
            $total += $result->affectedRows;
        }

        return $total;
    }

    public function executeInTransaction(): int
    {
        return DB::transaction(function () {
            return $this->execute();
        }, $this->connection);
    }
}
