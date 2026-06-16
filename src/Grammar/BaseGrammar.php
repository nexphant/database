<?php

namespace Nexphant\Database\Grammar;

abstract class BaseGrammar implements DriverGrammar
{
    public function compileSelect(array $columns): string
    {
        return 'SELECT ' . implode(', ', $columns);
    }

    public function compileFrom(string $table): string
    {
        return ' FROM ' . $this->quoteIdentifier($table);
    }

    public function compileWhere(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }
        $sql = [];
        foreach ($conditions as $i => $c) {
            $prefix = $i === 0 ? '' : " {$c['type']} ";
            if (isset($c['raw'])) {
                $sql[] = $prefix . $c['raw'];
                continue;
            }
            if (isset($c['between'])) {
                $sql[] = $prefix . $this->quoteIdentifier($c['column']) . ' BETWEEN ? AND ?';
                continue;
            }
            if (isset($c['in'])) {
                $placeholders = implode(', ', array_fill(0, $c['in'], '?'));
                $sql[] = $prefix . $this->quoteIdentifier($c['column']) . " IN ({$placeholders})";
                continue;
            }
            if (isset($c['null'])) {
                $op = $c['null'] ? 'IS NULL' : 'IS NOT NULL';
                $sql[] = $prefix . $this->quoteIdentifier($c['column']) . " {$op}";
                continue;
            }
            $sql[] = $prefix . $this->quoteIdentifier($c['column']) . " {$c['operator']} " . $this->parameterPlaceholder($i);
        }
        return ' WHERE ' . implode('', $sql);
    }

    public function compileJoin(array $joins): string
    {
        if (empty($joins)) {
            return '';
        }
        $sql = [];
        foreach ($joins as $join) {
            $sql[] = " {$join['type']} JOIN " . $this->quoteIdentifier($join['table'])
                . " ON {$join['first']} {$join['operator']} {$join['second']}";
        }
        return implode('', $sql);
    }

    public function compileOrderBy(array $orders): string
    {
        if (empty($orders)) {
            return '';
        }
        return ' ORDER BY ' . implode(', ', array_map(
            fn($o) => "{$o['column']} {$o['direction']}",
            $orders
        ));
    }

    public function compileLimit(?int $limit): string
    {
        return $limit !== null ? " LIMIT {$limit}" : '';
    }

    public function compileOffset(?int $offset): string
    {
        return $offset !== null ? " OFFSET {$offset}" : '';
    }

    public function compileGroupBy(array $groups): string
    {
        if (empty($groups)) {
            return '';
        }
        return ' GROUP BY ' . implode(', ', $groups);
    }

    public function compileHaving(array $havings): string
    {
        if (empty($havings)) {
            return '';
        }
        $sql = [];
        foreach ($havings as $i => $h) {
            $prefix = $i === 0 ? '' : " {$h['type']} ";
            if (isset($h['raw'])) {
                $sql[] = $prefix . $h['raw'];
            } else {
                $sql[] = $prefix . "{$h['column']} {$h['operator']} ?";
            }
        }
        return ' HAVING ' . implode('', $sql);
    }

    public function compileLock(?string $lock): string
    {
        return $lock ? " {$lock}" : '';
    }

    public function compileDelete(string $table): string
    {
        return 'DELETE FROM ' . $this->quoteIdentifier($table);
    }

    public function compileBatchDelete(string $table, string $column, int $count): string
    {
        $placeholders = implode(', ', array_fill(0, $count, '?'));
        return 'DELETE FROM ' . $this->quoteIdentifier($table)
            . ' WHERE ' . $this->quoteIdentifier($column) . " IN ({$placeholders})";
    }
}
