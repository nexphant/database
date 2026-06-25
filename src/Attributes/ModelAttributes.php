<?php

namespace Nexphant\Database\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(public readonly string $name) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Id {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(public readonly string $name = '') {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Hidden {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Unique {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Email {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Length
{
    public function __construct(
        public readonly int $min = 0,
        public readonly int $max = 0,
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class CreatedAt {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class UpdatedAt {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class DeletedAt {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Cast
{
    public function __construct(public readonly string $type) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Nullable {}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class DefaultValue
{
    public function __construct(public readonly mixed $value = null) {}
}
