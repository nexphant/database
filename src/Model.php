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

use Nexphant\Foundation\AttributeReader;

/**
 * Typed Model — base class for attribute-driven typed models.
 *
 * - No magic properties (typed public properties only)
 * - No lazy loading (explicit queries)
 * - No relationship magic (manual association methods)
 * - Metadata-first design
 */
abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static string $connection = 'default';

    /**
     * Create a model instance from a database row.
     */
    public static function hydrate(array $row): static
    {
        $instance = new static();
        $ref      = new \ReflectionClass(static::class);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (array_key_exists($name, $row)) {
                $prop->setValue($instance, $row[$name]);
            }
        }

        return $instance;
    }

    /**
     * Convert the model to an array.
     */
    public function toArray(): array
    {
        $result = [];
        $ref    = new \ReflectionClass($this);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $result[$prop->getName()] = $prop->getValue($this);
        }

        return $result;
    }

    /**
     * Get the table name.
     */
    public static function getTable(): string
    {
        return static::$table ?: strtolower((new \ReflectionClass(static::class))->getShortName()) . 's';
    }

    /**
     * Get the primary key column name.
     */
    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * Start a query builder for this model.
     */
    public static function query(): QueryBuilder
    {
        return (new QueryBuilder(static::getTable()))
            ->connection(static::$connection);
    }

    /**
     * Find a record by primary key.
     */
    public static function find(int|string $id): ?static
    {
        $row = static::query()
            ->where(static::$primaryKey, '=', $id)
            ->first();

        return $row ? static::hydrate($row) : null;
    }

    /**
     * Get all records.
     */
    public static function all(): array
    {
        $rows = static::query()->get();
        return array_map(fn($r) => static::hydrate($r), $rows);
    }

    /**
     * Insert the model into the database.
     */
    public function save(): int|string
    {
        $data = $this->toArray();
        return DB::table(static::getTable())
            ->connection(static::$connection)
            ->insert($data);
    }

    /**
     * Update the model in the database.
     */
    public function update(array $attributes = []): int
    {
        $pk    = static::$primaryKey;
        $id    = $this->{$pk} ?? null;

        if ($id === null) {
            throw new \RuntimeException('Cannot update model without primary key value');
        }

        $data = array_merge($this->toArray(), $attributes);
        unset($data[$pk]); // Don't update PK

        return DB::table(static::getTable())
            ->connection(static::$connection)
            ->where($pk, '=', $id)
            ->update($data);
    }

    /**
     * Delete the model from the database.
     */
    public function delete(): int
    {
        $pk = static::$primaryKey;
        $id = $this->{$pk} ?? null;

        if ($id === null) {
            throw new \RuntimeException('Cannot delete model without primary key value');
        }

        return DB::table(static::getTable())
            ->connection(static::$connection)
            ->where($pk, '=', $id)
            ->delete();
    }
}
