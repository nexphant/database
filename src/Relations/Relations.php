<?php

namespace Nexphant\Database\Relations;

/**
 * Immutable relation metadata objects.
 *
 * Usage in model:
 *   public static function relations(): array {
 *       return [
 *           'posts'   => HasMany::make(Post::class),
 *           'phone'   => HasOne::make(Phone::class),
 *           'company' => BelongsTo::make(Company::class),
 *       ];
 *   }
 */

abstract class Relation
{
    protected function __construct(
        public readonly string $related,
        public readonly ?string $foreignKey = null,
        public readonly ?string $localKey   = null,
    ) {}
}

final class HasOne extends Relation
{
    public static function make(
        string $related,
        ?string $foreignKey = null,
        ?string $localKey   = null,
    ): self {
        return new self($related, $foreignKey, $localKey);
    }
}

final class HasMany extends Relation
{
    public static function make(
        string $related,
        ?string $foreignKey = null,
        ?string $localKey   = null,
    ): self {
        return new self($related, $foreignKey, $localKey);
    }
}

final class BelongsTo extends Relation
{
    public static function make(
        string $related,
        ?string $foreignKey = null,
        ?string $ownerKey   = null,
    ): self {
        return new self($related, $foreignKey, $ownerKey);
    }
}

final class BelongsToMany extends Relation
{
    public function __construct(
        string $related,
        public readonly string $pivotTable,
        ?string $foreignKey = null,
        ?string $relatedKey = null,
    ) {
        parent::__construct($related, $foreignKey, $relatedKey);
    }

    public static function make(
        string $related,
        string $pivotTable,
        ?string $foreignKey = null,
        ?string $relatedKey = null,
    ): self {
        return new self($related, $pivotTable, $foreignKey, $relatedKey);
    }
}

final class HasManyThrough extends Relation
{
    public function __construct(
        string $related,
        public readonly string $through,
        ?string $firstKey  = null,
        ?string $secondKey = null,
    ) {
        parent::__construct($related, $firstKey, $secondKey);
    }

    public static function make(
        string $related,
        string $through,
        ?string $firstKey  = null,
        ?string $secondKey = null,
    ): self {
        return new self($related, $through, $firstKey, $secondKey);
    }
}
