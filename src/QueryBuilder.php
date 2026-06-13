<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nexph\Database;

class QueryBuilder
{
    private string $table;
    private string $connection = 'default';
    private array $select = ['*'];
    private array $where = [];
    private array $bindings = [];
    private array $joins = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $coalesce = false;
    private ?float $cacheTtl = null;
    private array $groupBy = [];
    private array $having = [];
    private ?string $lock = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function connection(string $connection): self
    {
        $this->connection = $connection;
        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $operator = $this->normalizeOperator($operator);
        $this->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value, 'type' => 'AND'];
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $operator = $this->normalizeOperator($operator);
        $this->where[] = ['column' => $column, 'operator' => $operator, 'value' => $value, 'type' => 'OR'];
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            $this->where[] = ['raw' => '1 = 0', 'type' => 'AND'];
            return $this;
        }

        $this->where[] = ['column' => $column, 'operator' => 'IN', 'value' => $values, 'type' => 'AND'];
        array_push($this->bindings, ...array_values($values));
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->where[] = ['column' => $column, 'operator' => 'IS NULL', 'value' => null, 'type' => 'AND'];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->where[] = ['column' => $column, 'operator' => 'IS NOT NULL', 'value' => null, 'type' => 'AND'];
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = ['type' => 'INNER', 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = ['type' => 'LEFT', 'table' => $table, 'first' => $first, 'operator' => $operator, 'second' => $second];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        $this->orderBy[] = ['column' => $column, 'direction' => in_array($direction, ['ASC', 'DESC'], true) ? $direction : 'ASC'];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function coalesce(bool $enabled = true): self
    {
        $this->coalesce = $enabled;
        return $this;
    }

    public function cache(float $seconds): self
    {
        $this->cacheTtl = max(0.0, $seconds);
        return $this->coalesce();
    }

    public function paginate(int $page, int $perPage): array
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        $data = $this->get();
        $total = $this->count();
        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage)
        ];
    }

    public function get(): array
    {
        $sql = $this->toSql();
        if ($this->coalesce || ($this->cacheTtl !== null && $this->cacheTtl > 0.0)) {
            return DB::coalescedQuery($sql, $this->bindings, $this->connection, $this->cacheTtl, $this->coalesce);
        }
        return DB::query($sql, $this->bindings, $this->connection);
    }

    public function first(): ?array
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

    public function count(): int
    {
        $originalSelect = $this->select;
        $originalOrderBy = $this->orderBy;
        $originalLimit = $this->limit;
        $originalOffset = $this->offset;
        $this->select = ['COUNT(*) as count'];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $sql = $this->toSql();
        $this->select = $originalSelect;
        $this->orderBy = $originalOrderBy;
        $this->limit = $originalLimit;
        $this->offset = $originalOffset;
        $result = $this->coalesce || ($this->cacheTtl !== null && $this->cacheTtl > 0.0)
            ? DB::coalescedQuery($sql, $this->bindings, $this->connection, $this->cacheTtl, $this->coalesce)
            : DB::query($sql, $this->bindings, $this->connection);
        return (int) ($result[0]['count'] ?? 0);
    }

    public function exists(): bool
    {
        $query = clone $this;
        return $query->limit(1)->count() > 0;
    }

    public function value(string $column): mixed
    {
        $query = clone $this;
        $row = $query->select([$column])->first();
        return $row[$column] ?? null;
    }

    public function pluck(string $column): array
    {
        $query = clone $this;
        return array_column($query->select([$column])->get(), $column);
    }

    public function insert(array $data): bool
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO {$this->quoteIdentifier($this->table)} (" . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ") VALUES (" . implode(', ', $placeholders) . ")";
        return DB::execute($sql, array_values($data), $this->connection);
    }

    public function insertGetId(array $data, string $returning = 'id'): string
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO {$this->quoteIdentifier($this->table)} (" . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ") VALUES (" . implode(', ', $placeholders) . ") RETURNING {$this->quoteIdentifier($returning)}";
        $result = DB::query($sql, array_values($data), $this->connection);
        if (!empty($result)) {
            return (string) ($result[0][$returning] ?? '');
        }
        // Fallback for drivers without RETURNING
        return DB::lastInsertId($this->connection);
    }

    public function bulkInsert(array $rows): int
    {
        if (empty($rows))
            return 0;
        $columns = array_keys($rows[0]);
        $colCount = count($columns);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, $colCount, '?')) . ')';
        $sql = "INSERT INTO {$this->quoteIdentifier($this->table)} (" . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ") VALUES "
            . implode(', ', array_fill(0, count($rows), $rowPlaceholder));
        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $bindings[] = $row[$col] ?? null;
            }
        }
        DB::execute($sql, $bindings, $this->connection);
        return count($rows);
    }

    public function upsert(array $data, array $uniqueColumns, array $updateColumns): bool
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $updates = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c) . ' = VALUES(' . $this->quoteIdentifier($c) . ')', $updateColumns));
        $sql = "INSERT INTO {$this->quoteIdentifier($this->table)} (" . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ") VALUES ("
            . implode(', ', $placeholders) . ") ON DUPLICATE KEY UPDATE {$updates}";
        return DB::execute($sql, array_values($data), $this->connection);
    }

    public function groupBy(string ...$columns): self
    {
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $this->having[] = ['column' => $column, 'operator' => $operator, 'value' => $value, 'type' => 'AND'];
        $this->bindings[] = $value;
        return $this;
    }

    public function lockForUpdate(): self
    {
        $this->lock = 'FOR UPDATE';
        return $this;
    }

    public function sharedLock(): self
    {
        $this->lock = 'LOCK IN SHARE MODE';
        return $this;
    }

    public function cursor(int $chunkSize = 100): Streaming\ResultCursor
    {
        return new Streaming\ResultCursor($this->toSql(), $this->bindings, $this->connection, $chunkSize);
    }

    public function update(array $data): bool
    {
        $set = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . " = ?";
            $bindings[] = $value;
        }
        $sql = "UPDATE {$this->quoteIdentifier($this->table)} SET " . implode(', ', $set);
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere();
            $bindings = array_merge($bindings, $this->bindings);
        }
        return DB::execute($sql, $bindings, $this->connection);
    }

    public function delete(): bool
    {
        $sql = "DELETE FROM {$this->quoteIdentifier($this->table)}";
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere();
        }
        return DB::execute($sql, $this->bindings, $this->connection);
    }

    public function toSql(): string
    {
        $sql = "SELECT " . implode(', ', array_map([$this, 'quoteSelectable'], $this->select)) . " FROM {$this->quoteIdentifier($this->table)}";
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $operator = $this->normalizeOperator($join['operator']);
                $sql .= " {$join['type']} JOIN {$this->quoteIdentifier($join['table'])} ON {$this->quoteIdentifier($join['first'])} {$operator} {$this->quoteIdentifier($join['second'])}";
            }
        }
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . $this->buildWhere();
        }
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', array_map([$this, 'quoteIdentifier'], $this->groupBy));
        }
        if (!empty($this->having)) {
            $sql .= ' HAVING ' . $this->buildHaving();
        }
        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(fn($o) => $this->quoteIdentifier($o['column']) . " {$o['direction']}", $this->orderBy));
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        if ($this->lock) {
            $sql .= " {$this->lock}";
        }
        return $sql;
    }

    private function buildHaving(): string
    {
        $parts = [];
        foreach ($this->having as $i => $h) {
            $prefix = $i === 0 ? '' : " {$h['type']} ";
            $parts[] = $prefix . $this->quoteIdentifier($h['column']) . " {$h['operator']} ?";
        }
        return implode('', $parts);
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    private function buildWhere(): string
    {
        $conditions = [];
        foreach ($this->where as $i => $w) {
            $prefix = $i === 0 ? '' : " {$w['type']} ";
            if (isset($w['raw'])) {
                $conditions[] = $prefix . $w['raw'];
                continue;
            }
            if ($w['operator'] === 'IN') {
                $placeholders = implode(', ', array_fill(0, count((array) $w['value']), '?'));
                $conditions[] = $prefix . $this->quoteIdentifier($w['column']) . " IN ({$placeholders})";
                continue;
            }
            if (in_array($w['operator'], ['IS NULL', 'IS NOT NULL'], true)) {
                $conditions[] = $prefix . $this->quoteIdentifier($w['column']) . " {$w['operator']}";
                continue;
            }
            $conditions[] = $prefix . $this->quoteIdentifier($w['column']) . " {$w['operator']} ?";
        }
        return implode('', $conditions);
    }

    private function normalizeOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        return in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'], true) ? $operator : '=';
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid SQL identifier');
        }
        return implode('.', array_map(fn($part) => "`{$part}`", explode('.', $identifier)));
    }

    private function quoteSelectable(string $column): string
    {
        if ($column === '*') {
            return '*';
        }
        if (preg_match('/^COUNT\(\*\)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $column, $m)) {
            return 'COUNT(*) AS ' . $this->quoteIdentifier($m[1]);
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_.]*)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $column, $m)) {
            return $this->quoteIdentifier($m[1]) . ' AS ' . $this->quoteIdentifier($m[2]);
        }
        return $this->quoteIdentifier($column);
    }
}
