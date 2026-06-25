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
 * Schema builder — creates and modifies database tables via an immutable builder.
 */
class SchemaBuilder
{
    private string $table;
    private array  $columns  = [];
    private array  $indexes  = [];
    private array  $foreign  = [];
    private bool   $ifNotExists = false;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public static function create(string $table): self
    {
        return (new self($table))->ifNotExists();
    }

    public static function table(string $table): self
    {
        return new self($table);
    }

    public function ifNotExists(): self
    {
        $clone = clone $this;
        $clone->ifNotExists = true;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Column helpers
    // -------------------------------------------------------------------------

    public function id(string $name = 'id'): self
    {
        return $this->column($name, 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY');
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->column($name, "VARCHAR({$length})");
    }

    public function text(string $name): self { return $this->column($name, 'TEXT'); }
    public function longText(string $name): self { return $this->column($name, 'LONGTEXT'); }

    public function integer(string $name, bool $unsigned = false): self
    {
        return $this->column($name, 'INT' . ($unsigned ? ' UNSIGNED' : ''));
    }

    public function bigInteger(string $name, bool $unsigned = false): self
    {
        return $this->column($name, 'BIGINT' . ($unsigned ? ' UNSIGNED' : ''));
    }

    public function float(string $name): self { return $this->column($name, 'FLOAT'); }
    public function decimal(string $name, int $total = 8, int $places = 2): self
    {
        return $this->column($name, "DECIMAL({$total},{$places})");
    }

    public function boolean(string $name): self { return $this->column($name, 'TINYINT(1)'); }
    public function json(string $name): self    { return $this->column($name, 'JSON'); }
    public function date(string $name): self    { return $this->column($name, 'DATE'); }
    public function datetime(string $name): self { return $this->column($name, 'DATETIME'); }
    public function timestamp(string $name): self { return $this->column($name, 'TIMESTAMP NULL DEFAULT NULL'); }

    public function timestamps(): self
    {
        return $this
            ->column('created_at', 'TIMESTAMP NULL DEFAULT NULL')
            ->column('updated_at', 'TIMESTAMP NULL DEFAULT NULL');
    }

    public function softDeletes(): self
    {
        return $this->column('deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
    }

    public function nullable(string $name, string $type): self
    {
        return $this->column($name, $type . ' NULL');
    }

    // -------------------------------------------------------------------------
    // Index helpers
    // -------------------------------------------------------------------------

    public function index(string|array $columns, ?string $name = null): self
    {
        $clone = clone $this;
        $cols  = implode(', ', (array) $columns);
        $iname = $name ?? 'idx_' . $this->table . '_' . implode('_', (array) $columns);
        $clone->indexes[] = "INDEX `{$iname}` ({$cols})";
        return $clone;
    }

    public function unique(string|array $columns, ?string $name = null): self
    {
        $clone = clone $this;
        $cols  = implode(', ', (array) $columns);
        $iname = $name ?? 'uniq_' . $this->table . '_' . implode('_', (array) $columns);
        $clone->indexes[] = "UNIQUE INDEX `{$iname}` ({$cols})";
        return $clone;
    }

    public function foreignKey(string $column, string $refTable, string $refCol = 'id', string $onDelete = 'CASCADE'): self
    {
        $clone = clone $this;
        $fname = 'fk_' . $this->table . '_' . $column;
        $clone->foreign[] = "CONSTRAINT `{$fname}` FOREIGN KEY (`{$column}`) REFERENCES `{$refTable}` (`{$refCol}`) ON DELETE {$onDelete}";
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Build SQL
    // -------------------------------------------------------------------------

    public function toSql(): string
    {
        $exists = $this->ifNotExists ? 'IF NOT EXISTS ' : '';
        $defs   = array_merge($this->columns, $this->indexes, $this->foreign);
        return "CREATE TABLE {$exists}`{$this->table}` (\n  " . implode(",\n  ", $defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    }

    public function run(string $connection = 'default'): void
    {
        DB::statement($this->toSql(), $connection);
    }

    // -------------------------------------------------------------------------

    private function column(string $name, string $def): self
    {
        $clone = clone $this;
        $clone->columns[] = "`{$name}` {$def}";
        return $clone;
    }
}
