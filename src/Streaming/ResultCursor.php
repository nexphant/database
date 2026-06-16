<?php

namespace nexphant\Database\Streaming;

use nexphant\Database\DB;

class ResultCursor
{
    private bool $closed = false;

    public function __construct(
        private string $sql,
        private array $params = [],
        private string $connection = 'default',
        private int $chunkSize = 100,
    ) {
    }

    /**
     * @return \Generator<int, array>
     */
    public function cursor(): \Generator
    {
        $offset = 0;
        while (!$this->closed) {
            $chunk = DB::query(
                "{$this->sql} LIMIT {$this->chunkSize} OFFSET {$offset}",
                $this->params,
                $this->connection
            );

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                yield $row;
            }

            if (count($chunk) < $this->chunkSize) {
                break;
            }

            $offset += $this->chunkSize;
        }
    }

    /**
     * Keyset pagination (faster for large offsets)
     * @return \Generator<int, array>
     */
    public function keysetCursor(string $keyColumn = 'id', string $direction = 'ASC'): \Generator
    {
        $lastKey = null;
        $op = strtoupper($direction) === 'ASC' ? '>' : '<';

        while (!$this->closed) {
            $sql = $this->sql;
            $params = $this->params;

            if ($lastKey !== null) {
                $hasWhere = stripos($sql, 'WHERE') !== false;
                $sql .= ($hasWhere ? ' AND' : ' WHERE') . " `{$keyColumn}` {$op} ?";
                $params[] = $lastKey;
            }

            $sql .= " ORDER BY `{$keyColumn}` {$direction} LIMIT {$this->chunkSize}";
            $chunk = DB::query($sql, $params, $this->connection);

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                $lastKey = $row[$keyColumn] ?? null;
                yield $row;
            }

            if (count($chunk) < $this->chunkSize) {
                break;
            }
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function each(callable $callback): int
    {
        $count = 0;
        foreach ($this->cursor() as $row) {
            $callback($row, $count);
            $count++;
        }
        return $count;
    }

    public function chunk(callable $callback): int
    {
        $offset = 0;
        $total = 0;

        while (!$this->closed) {
            $chunk = DB::query(
                "{$this->sql} LIMIT {$this->chunkSize} OFFSET {$offset}",
                $this->params,
                $this->connection
            );

            if (empty($chunk)) {
                break;
            }

            $callback($chunk);
            $total += count($chunk);

            if (count($chunk) < $this->chunkSize) {
                break;
            }

            $offset += $this->chunkSize;
        }

        return $total;
    }
}
