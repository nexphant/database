<?php

namespace Nexphant\Database\Attributes;

use Attribute;

/**
 * Column attribute — accepts variadic immutable Type metadata objects.
 *
 * #[Column(Type::email(), Type::unique(), Type::nullable())]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    /** @var array<ColumnMeta> */
    public readonly array $meta;

    public function __construct(ColumnMeta ...$meta)
    {
        $this->meta = $meta;
    }

    public function has(string $class): bool
    {
        foreach ($this->meta as $m) {
            if ($m instanceof $class) return true;
        }
        return false;
    }

    public function get(string $class): ?ColumnMeta
    {
        foreach ($this->meta as $m) {
            if ($m instanceof $class) return $m;
        }
        return null;
    }
}
