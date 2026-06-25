<?php

namespace Nexphant\Database;

use Nexphant\Database\Attributes\Column;
use Nexphant\Database\Attributes\Table;
use Nexphant\Database\Attributes\HiddenMeta;
use Nexphant\Database\Attributes\CastMeta;
use Nexphant\Database\Attributes\CreatedAtMeta;
use Nexphant\Database\Attributes\UpdatedAtMeta;
use Nexphant\Database\Attributes\DeletedAtMeta;
use Nexphant\Database\Attributes\UuidMeta;
use Nexphant\Database\Attributes\UlidMeta;
use Nexphant\Database\Relations\Relation;

/**
 * Model — Single Source of Truth base class.
 *
 * Principles:
 * - No magic properties (__get/__set forbidden)
 * - No lazy loading
 * - No ActiveRecord fat base class
 * - Metadata-driven (hydrate, cast, hide via #[Column] attributes)
 * - Explicit relation queries via relations() + relation()
 */
abstract class Model
{
    protected static string $table      = '';
    protected static string $primaryKey = 'id';
    protected static string $connection = 'default';

    // ── Metadata ─────────────────────────────────────────────────────────────

    /**
     * Override to define relationships.
     *
     * @return array<string, Relation>
     */
    public static function relations(): array
    {
        return [];
    }

    /**
     * Get table name — reads #[Table] attribute or falls back to snake_case plural.
     */
    public static function getTable(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }
        $attrs = (new \ReflectionClass(static::class))->getAttributes(Table::class);
        if ($attrs) {
            return $attrs[0]->newInstance()->name;
        }
        $name = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';
    }

    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    // ── Hydration ────────────────────────────────────────────────────────────

    /**
     * Create a typed model instance from a DB row array.
     */
    public static function hydrate(array $row): static
    {
        $instance = new static();
        $ref      = new \ReflectionClass(static::class);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $propName = $prop->getName();

            // Match DB column — direct name or snake_case variant
            $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $propName));
            $value = array_key_exists($propName, $row) ? $row[$propName]
                   : (array_key_exists($snake, $row) ? $row[$snake] : null);

            if ($value === null && !array_key_exists($propName, $row) && !array_key_exists($snake, $row)) {
                continue;
            }

            $value = static::castProperty($prop, $value);
            $prop->setValue($instance, $value);
        }

        return $instance;
    }

    /**
     * Cast a raw DB value to the property's declared type,
     * honouring #[Column(Type::cast(...))] when present.
     */
    protected static function castProperty(\ReflectionProperty $prop, mixed $value): mixed
    {
        // Explicit cast via #[Column(Type::cast('json'))]
        $colAttrs = $prop->getAttributes(Column::class);
        if ($colAttrs) {
            $col  = $colAttrs[0]->newInstance();
            $cast = $col->get(CastMeta::class);
            if ($cast) {
                return static::applyCast($value, $cast->type);
            }
        }

        // Cast by declared PHP type
        $type = $prop->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        if (!$type->isBuiltin()) {
            $name = $type->getName();
            if ($name === \DateTimeImmutable::class && $value !== null) {
                return new \DateTimeImmutable((string) $value);
            }
            return $value;
        }

        if ($value === null) {
            return $type->allowsNull() ? null : match ($type->getName()) {
                'int'    => 0,
                'float'  => 0.0,
                'bool'   => false,
                'string' => '',
                'array'  => [],
                default  => null,
            };
        }

        return match ($type->getName()) {
            'int'    => (int)    $value,
            'float'  => (float)  $value,
            'bool'   => (bool)   $value,
            'string' => (string) $value,
            'array'  => is_string($value) ? json_decode($value, true) : (array) $value,
            default  => $value,
        };
    }

    protected static function applyCast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int'      => (int)    $value,
            'float'    => (float)  $value,
            'bool'     => (bool)   $value,
            'string'   => (string) $value,
            'array',
            'json'     => is_string($value) ? json_decode($value, true) : $value,
            'datetime' => $value ? new \DateTimeImmutable((string) $value) : null,
            default    => $value,
        };
    }

    // ── Serialization ─────────────────────────────────────────────────────────

    /**
     * All public properties as array (including hidden).
     */
    public function toArray(): array
    {
        $result = [];
        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $v = $prop->getValue($this);
            $result[$prop->getName()] = $v instanceof \DateTimeImmutable ? $v->format('Y-m-d H:i:s') : $v;
        }
        return $result;
    }

    /**
     * Public properties excluding #[Column(Type::hidden())] fields.
     */
    public function toVisibleArray(): array
    {
        $result = [];
        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $attrs = $prop->getAttributes(Column::class);
            if ($attrs && $attrs[0]->newInstance()->has(HiddenMeta::class)) {
                continue;
            }
            $v = $prop->getValue($this);
            $result[$prop->getName()] = $v instanceof \DateTimeImmutable ? $v->format('Y-m-d H:i:s') : $v;
        }
        return $result;
    }

    // ── Query API ─────────────────────────────────────────────────────────────

    public static function query(): QueryBuilder
    {
        return (new QueryBuilder(static::getTable()))->connection(static::$connection);
    }

    public static function find(int|string $id): ?static
    {
        $row = static::query()->where(static::$primaryKey, '=', $id)->first();
        return $row ? static::hydrate($row) : null;
    }

    public static function all(): array
    {
        return array_map(fn($r) => static::hydrate($r), static::query()->get());
    }

    public static function create(array $data): static
    {
        $instance = new static();
        $ref      = new \ReflectionClass(static::class);
        foreach ($data as $k => $v) {
            if ($ref->hasProperty($k) && $ref->getProperty($k)->isPublic()) {
                $instance->{$k} = $v;
            }
        }
        $instance->save();
        return $instance;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function save(): int|string
    {
        $data = $this->toArray();
        $pk   = static::$primaryKey;

        // Auto UUID / ULID if #[Column(Type::uuid())] or Type::ulid()
        if (!isset($data[$pk]) || (int)($data[$pk] ?? 0) === 0) {
            $colAttr = $this->pkColumnAttr();
            if ($colAttr && $colAttr->has(UuidMeta::class)) {
                $data[$pk] = $this->generateUuid();
            } elseif ($colAttr && $colAttr->has(UlidMeta::class)) {
                $data[$pk] = $this->generateUlid();
            } else {
                unset($data[$pk]);
            }
        }

        $id = DB::table(static::getTable())->connection(static::$connection)->insertGetId($data);

        if ($id && property_exists($this, $pk)) {
            $this->{$pk} = is_numeric($id) ? (int)$id : $id;
        }

        return $id;
    }

    public function update(array $attributes = []): int
    {
        $pk  = static::$primaryKey;
        $id  = $this->{$pk} ?? null;
        if ($id === null) throw new \RuntimeException('Cannot update model without primary key');

        $data = array_merge($this->toArray(), $attributes);
        unset($data[$pk]);

        return DB::table(static::getTable())->connection(static::$connection)
            ->where($pk, '=', $id)->update($data);
    }

    public function delete(): int
    {
        $pk = static::$primaryKey;
        $id = $this->{$pk} ?? null;
        if ($id === null) throw new \RuntimeException('Cannot delete model without primary key');

        return DB::table(static::getTable())->connection(static::$connection)
            ->where($pk, '=', $id)->delete();
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * Get a relation query builder by name.
     *
     * Usage: $user->relation('posts')->get()
     */
    public function relation(string $name): QueryBuilder
    {
        $relations = static::relations();
        if (!isset($relations[$name])) {
            throw new \InvalidArgumentException("Relation '{$name}' not defined on " . static::class);
        }

        $rel        = $relations[$name];
        $relClass   = $rel->related;
        $relTable   = $relClass::getTable();
        $pk         = static::$primaryKey;
        $fk         = $rel->foreignKey ?? $this->guessFK();
        $localKey   = $rel->localKey ?? $pk;
        $localValue = $this->{$localKey} ?? null;

        return (new QueryBuilder($relTable))
            ->connection(static::$connection)
            ->where($fk, '=', $localValue);
    }

    protected function guessFK(): string
    {
        $name = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . '_id';
    }

    protected function pkColumnAttr(): ?\Nexphant\Database\Attributes\Column
    {
        $ref = new \ReflectionClass(static::class);
        $pk  = static::$primaryKey;
        if (!$ref->hasProperty($pk)) return null;
        $attrs = $ref->getProperty($pk)->getAttributes(Column::class);
        return $attrs ? $attrs[0]->newInstance() : null;
    }

    protected function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    protected function generateUlid(): string
    {
        $t   = (int)(microtime(true) * 1000);
        $enc = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $r   = '';
        for ($i = 0; $i < 10; $i++) {
            $r = $enc[$t & 31] . $r;
            $t >>= 5;
        }
        for ($i = 0; $i < 16; $i++) {
            $r .= $enc[random_int(0, 31)];
        }
        return $r;
    }
}
