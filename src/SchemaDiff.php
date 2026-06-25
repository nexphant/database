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

/**
 * SchemaDiff — compares model metadata against live database schema
 * and generates migration SQL statements.
 */
class SchemaDiff
{
    /**
     * Compare a model class against a live DB table and return diff.
     *
     * @return array{add: array, modify: array, drop: array, indexes: array}
     */
    public function diff(string $modelClass, string $connection = 'default'): array
    {
        $table   = method_exists($modelClass, 'getTable') ? $modelClass::getTable() : '';
        $desired = $this->modelColumns($modelClass);
        $actual  = $this->liveColumns($table, $connection);

        $add    = [];
        $modify = [];
        $drop   = [];

        foreach ($desired as $col => $def) {
            if (!isset($actual[$col])) {
                $add[$col] = $def;
            } elseif (strtolower($actual[$col]) !== strtolower($def)) {
                $modify[$col] = $def;
            }
        }

        foreach ($actual as $col => $_) {
            if (!isset($desired[$col])) {
                $drop[] = $col;
            }
        }

        return compact('add', 'modify', 'drop', 'table');
    }

    /**
     * Generate ALTER TABLE SQL from a diff result.
     */
    public function toSql(array $diff): string
    {
        $table = $diff['table'];
        $stmts = [];

        foreach ($diff['add'] as $col => $def) {
            $stmts[] = "ADD COLUMN `{$col}` {$def}";
        }
        foreach ($diff['modify'] as $col => $def) {
            $stmts[] = "MODIFY COLUMN `{$col}` {$def}";
        }
        foreach ($diff['drop'] as $col) {
            $stmts[] = "DROP COLUMN `{$col}`";
        }

        if (empty($stmts)) return '';

        return "ALTER TABLE `{$table}`\n  " . implode(",\n  ", $stmts) . ';';
    }

    /**
     * Generate migration file content from a diff.
     */
    public function toMigration(array $diff): string
    {
        $sql     = addslashes($this->toSql($diff));
        $table   = $diff['table'];

        return <<<PHP
<?php
use Nexphant\Database\Schema;

return new class {
    public function up(Schema \$schema): void
    {
        \$schema->statement("{$sql}");
    }

    public function down(Schema \$schema): void
    {
        // TODO: reverse migration for `{$table}`
    }
};
PHP;
    }

    // -------------------------------------------------------------------------

    /** @return array<string, string> column => type */
    private function modelColumns(string $class): array
    {
        $ref     = new \ReflectionClass($class);
        $columns = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (in_array($name, ['table', 'primaryKey', 'connection'], true)) continue;
            $type = $prop->getType();
            $columns[$name] = $this->phpTypeToSql(
                $type instanceof \ReflectionNamedType ? $type->getName() : 'string',
                $type?->allowsNull() ?? true
            );
        }

        return $columns;
    }

    /** @return array<string, string> column => type */
    private function liveColumns(string $table, string $connection): array
    {
        try {
            $rows = DB::select("SHOW COLUMNS FROM `{$table}`", [], $connection);
            $cols = [];
            foreach ($rows as $row) {
                $cols[$row['Field']] = strtoupper($row['Type']);
            }
            return $cols;
        } catch (\Throwable) {
            return [];
        }
    }

    private function phpTypeToSql(string $type, bool $nullable): string
    {
        $sql = match ($type) {
            'int', 'integer' => 'INT',
            'float', 'double' => 'DOUBLE',
            'bool', 'boolean' => 'TINYINT(1)',
            'array'           => 'JSON',
            default           => 'VARCHAR(255)',
        };
        return $nullable ? "{$sql} NULL" : "{$sql} NOT NULL";
    }
}
