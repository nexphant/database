<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nexphant\Database;

class Grammar
{
    public static function compileSelect(array $columns): string
    {
        return 'SELECT ' . implode(', ', $columns);
    }

    public static function compileFrom(string $table): string
    {
        return " FROM `{$table}`";
    }

    public static function compileWhere(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }
        $sql = [];
        foreach ($conditions as $i => $condition) {
            $prefix = $i === 0 ? '' : " {$condition['type']} ";
            $sql[] = $prefix . "`{$condition['column']}` {$condition['operator']} ?";
        }
        return ' WHERE ' . implode('', $sql);
    }

    public static function compileJoin(array $joins): string
    {
        if (empty($joins)) {
            return '';
        }
        $sql = [];
        foreach ($joins as $join) {
            $sql[] = " {$join['type']} JOIN `{$join['table']}` ON {$join['first']} {$join['operator']} {$join['second']}";
        }
        return implode('', $sql);
    }

    public static function compileOrderBy(array $orders): string
    {
        if (empty($orders)) {
            return '';
        }
        return ' ORDER BY ' . implode(', ', array_map(fn($o) => "{$o['column']} {$o['direction']}", $orders));
    }

    public static function compileLimit(?int $limit): string
    {
        return $limit !== null ? " LIMIT {$limit}" : '';
    }

    public static function compileOffset(?int $offset): string
    {
        return $offset !== null ? " OFFSET {$offset}" : '';
    }

    public static function compileInsert(string $table, array $columns): string
    {
        $placeholders = array_fill(0, count($columns), '?');
        return "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
    }

    public static function compileUpdate(string $table, array $columns): string
    {
        $set = array_map(fn($col) => "`{$col}` = ?", $columns);
        return "UPDATE `{$table}` SET " . implode(', ', $set);
    }

    public static function compileDelete(string $table): string
    {
        return "DELETE FROM `{$table}`";
    }
}
