<?php

namespace Nexph\Database\Grammar;

interface DriverGrammar
{
    public function quoteIdentifier(string $name): string;
    public function compileInsert(string $table, array $columns): string;
    public function compileInsertReturning(string $table, array $columns, string $returning = 'id'): string;
    public function compileBulkInsert(string $table, array $columns, int $rowCount): string;
    public function compileUpdate(string $table, array $columns): string;
    public function compileBatchUpdate(string $table, array $columns, string $keyColumn): string;
    public function compileDelete(string $table): string;
    public function compileBatchDelete(string $table, string $column, int $count): string;
    public function compileSelect(array $columns): string;
    public function compileFrom(string $table): string;
    public function compileWhere(array $conditions): string;
    public function compileJoin(array $joins): string;
    public function compileOrderBy(array $orders): string;
    public function compileLimit(?int $limit): string;
    public function compileOffset(?int $offset): string;
    public function compileGroupBy(array $groups): string;
    public function compileHaving(array $havings): string;
    public function compileLock(?string $lock): string;
    public function compileExplain(string $sql): string;
    public function parameterPlaceholder(int $index): string;
    public function supportsReturning(): bool;
}
