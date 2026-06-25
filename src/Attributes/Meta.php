<?php

namespace Nexphant\Database\Attributes;

/**
 * All immutable ColumnMeta value objects.
 * Declared in one file — autoloaded via classmap.
 */

final class IdMeta       implements ColumnMeta {}
final class UuidMeta     implements ColumnMeta {}
final class UlidMeta     implements ColumnMeta {}
final class TextMeta     implements ColumnMeta {}
final class EmailMeta    implements ColumnMeta {}
final class PasswordMeta implements ColumnMeta {}
final class JsonMeta     implements ColumnMeta {}
final class BooleanMeta  implements ColumnMeta {}
final class FloatMeta    implements ColumnMeta {}
final class DatetimeMeta implements ColumnMeta {}
final class DateMeta     implements ColumnMeta {}
final class TimestampMeta  implements ColumnMeta {}
final class CreatedAtMeta  implements ColumnMeta {}
final class UpdatedAtMeta  implements ColumnMeta {}
final class DeletedAtMeta  implements ColumnMeta {}
final class NullableMeta   implements ColumnMeta {}
final class HiddenMeta     implements ColumnMeta {}
final class FillableMeta   implements ColumnMeta {}

final class StringMeta implements ColumnMeta {
    public function __construct(public readonly int $length = 255) {}
}
final class IntegerMeta implements ColumnMeta {
    public function __construct(public readonly bool $unsigned = false) {}
}
final class BigIntMeta implements ColumnMeta {
    public function __construct(public readonly bool $unsigned = false) {}
}
final class DecimalMeta implements ColumnMeta {
    public function __construct(public readonly int $precision = 8, public readonly int $scale = 2) {}
}
final class UniqueMeta implements ColumnMeta {
    public function __construct(public readonly ?string $name = null) {}
}
final class IndexMeta implements ColumnMeta {
    public function __construct(public readonly ?string $name = null) {}
}
final class DefaultMeta implements ColumnMeta {
    public function __construct(public readonly mixed $value = null) {}
}
final class CommentMeta implements ColumnMeta {
    public function __construct(public readonly string $text = '') {}
}
final class LengthMeta implements ColumnMeta {
    public function __construct(public readonly int $min = 0, public readonly int $max = 0) {}
}
final class PrecisionMeta implements ColumnMeta {
    public function __construct(public readonly int $value = 8) {}
}
final class ScaleMeta implements ColumnMeta {
    public function __construct(public readonly int $value = 2) {}
}
final class CastMeta implements ColumnMeta {
    public function __construct(public readonly string $type = '') {}
}
