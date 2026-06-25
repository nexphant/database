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

use Nexphant\Database\Attributes\Table;
use Nexphant\Database\Attributes\Hidden;
use Nexphant\Database\Attributes\Cast;
use Nexphant\Database\Attributes\CreatedAt;
use Nexphant\Database\Attributes\UpdatedAt;
use Nexphant\Database\Attributes\DeletedAt;

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
            $key  = $name; // column name == property name

            // Support snake_case DB columns mapped to camelCase properties
            $snake = strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
            $value = array_key_exists($key, $row) ? $row[$key]
                   : (array_key_exists($snake, $row) ? $row[$snake] : null);

            if ($value === null && !array_key_exists($key, $row) && !array_key_exists($snake, $row)) {
                continue;
            }

            // Cast via #[Cast] attribute
            $castAttrs = $prop->getAttributes(Cast::class);
            if ($castAttrs) {
                $type  = $castAttrs[0]->newInstance()->type;
                $value = static::castValue($value, $type);
            } else {
                // Cast by declared type
                $type = $prop->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    if ($typeName === \DateTimeImmutable::class && $value !== null) {
                        $value = new \DateTimeImmutable($value);
                    }
                } elseif ($type instanceof \ReflectionNamedType) {
                    $value = match($type->getName()) {
                        'int'   => $value !== null ? (int)$value : ($type->allowsNull() ? null : 0),
                        'float' => $value !== null ? (float)$value : ($type->allowsNull() ? null : 0.0),
                        'bool'  => $value !== null ? (bool)$value : ($type->allowsNull() ? null : false),
                        'string'=> $value !== null ? (string)$value : ($type->allowsNull() ? null : ''),
                        default => $value,
                    };
                }
            }

            $prop->setValue($instance, $value);
        }

        return $instance;
    }

    protected static function castValue(mixed $value, string $type): mixed
    {
        return match($type) {
            'int'      => (int) $value,
            'float'    => (float) $value,
            'bool'     => (bool) $value,
            'string'   => (string) $value,
            'array'    => is_string($value) ? json_decode($value, true) : (array) $value,
            'json'     => is_string($value) ? json_decode($value, true) : $value,
            'datetime' => $value ? new \DateTimeImmutable($value) : null,
            default    => $value,
        };
    }

    /**
     * Convert the model to an array.
     */
    public function toArray(): array
    {
        $result = [];
        $ref    = new \ReflectionClass($this);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($this);
            if ($value instanceof \DateTimeImmutable) {
                $value = $value->format('Y-m-d H:i:s');
            }
            $result[$prop->getName()] = $value;
        }

        return $result;
    }

    /**
     * Array excluding #[Hidden] properties.
     */
    public function toVisibleArray(): array
    {
        $result = [];
        $ref    = new \ReflectionClass($this);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getAttributes(Hidden::class)) {
                continue;
            }
            $value = $prop->getValue($this);
            if ($value instanceof \DateTimeImmutable) {
                $value = $value->format('Y-m-d H:i:s');
            }
            $result[$prop->getName()] = $value;
        }

        return $result;
    }

    /**
     * Get the table name.
     */
    public static function getTable(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }
        // Read #[Table] attribute
        $attrs = (new \ReflectionClass(static::class))->getAttributes(Table::class);
        if ($attrs) {
            return $attrs[0]->newInstance()->name;
        }
        // Fallback: snake_case plural of class name
        $name = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name))) . 's';
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
        $pk   = static::$primaryKey;
        // Remove PK if zero/null so DB auto-assigns it
        if (isset($data[$pk]) && (int) $data[$pk] === 0) {
            unset($data[$pk]);
        }
        $id = DB::table(static::getTable())
            ->connection(static::$connection)
            ->insertGetId($data);
        // Reflect new ID back onto model
        if ($id && property_exists($this, $pk)) {
            $this->{$pk} = (int) $id;
        }
        return $id;
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
