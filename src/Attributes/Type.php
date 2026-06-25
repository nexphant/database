<?php

namespace Nexphant\Database\Attributes;

/**
 * Type — immutable metadata factory.
 *
 * All methods return ColumnMeta objects consumed by #[Column(...)].
 *
 * Usage:
 *   #[Column(new EmailMeta(), new UniqueMeta(), new NullableMeta())]
 *
 * Or via factory (runtime only, not in attribute args):
 *   Type::email()  // returns new EmailMeta()
 */
final class Type
{
    public static function id(): IdMeta             { return new IdMeta(); }
    public static function uuid(): UuidMeta         { return new UuidMeta(); }
    public static function ulid(): UlidMeta         { return new UlidMeta(); }
    public static function string(int $length = 255): StringMeta  { return new StringMeta($length); }
    public static function text(): TextMeta                        { return new TextMeta(); }
    public static function email(): EmailMeta                      { return new EmailMeta(); }
    public static function password(): PasswordMeta                { return new PasswordMeta(); }
    public static function json(): JsonMeta                        { return new JsonMeta(); }
    public static function boolean(): BooleanMeta                  { return new BooleanMeta(); }
    public static function integer(bool $unsigned = false): IntegerMeta   { return new IntegerMeta($unsigned); }
    public static function bigInteger(bool $unsigned = false): BigIntMeta { return new BigIntMeta($unsigned); }
    public static function decimal(int $p = 8, int $s = 2): DecimalMeta   { return new DecimalMeta($p, $s); }
    public static function float(): FloatMeta                      { return new FloatMeta(); }
    public static function datetime(): DatetimeMeta                { return new DatetimeMeta(); }
    public static function date(): DateMeta                        { return new DateMeta(); }
    public static function timestamp(): TimestampMeta              { return new TimestampMeta(); }
    public static function createdAt(): CreatedAtMeta              { return new CreatedAtMeta(); }
    public static function updatedAt(): UpdatedAtMeta              { return new UpdatedAtMeta(); }
    public static function deletedAt(): DeletedAtMeta              { return new DeletedAtMeta(); }
    public static function nullable(): NullableMeta                { return new NullableMeta(); }
    public static function unique(?string $name = null): UniqueMeta { return new UniqueMeta($name); }
    public static function index(?string $name = null): IndexMeta  { return new IndexMeta($name); }
    public static function hidden(): HiddenMeta                    { return new HiddenMeta(); }
    public static function default(mixed $value): DefaultMeta      { return new DefaultMeta($value); }
    public static function comment(string $text): CommentMeta      { return new CommentMeta($text); }
    public static function length(int $min, int $max): LengthMeta  { return new LengthMeta($min, $max); }
    public static function precision(int $p): PrecisionMeta        { return new PrecisionMeta($p); }
    public static function scale(int $s): ScaleMeta                { return new ScaleMeta($s); }
    public static function fillable(): FillableMeta                { return new FillableMeta(); }
    public static function cast(string $type): CastMeta            { return new CastMeta($type); }
}

    // ── Identity ─────────────────────────────────────────────────────────────

    public static function id(): IdMeta             { return new IdMeta(); }
    public static function uuid(): UuidMeta         { return new UuidMeta(); }
    public static function ulid(): UlidMeta         { return new UlidMeta(); }

    // ── Strings ───────────────────────────────────────────────────────────────

    public static function string(int $length = 255): StringMeta  { return new StringMeta($length); }
    public static function text(): TextMeta                        { return new TextMeta(); }
    public static function email(): EmailMeta                      { return new EmailMeta(); }
    public static function password(): PasswordMeta                { return new PasswordMeta(); }
    public static function json(): JsonMeta                        { return new JsonMeta(); }

    // ── Numbers ───────────────────────────────────────────────────────────────

    public static function boolean(): BooleanMeta                             { return new BooleanMeta(); }
    public static function integer(bool $unsigned = false): IntegerMeta       { return new IntegerMeta($unsigned); }
    public static function bigInteger(bool $unsigned = false): BigIntMeta     { return new BigIntMeta($unsigned); }
    public static function decimal(int $precision = 8, int $scale = 2): DecimalMeta { return new DecimalMeta($precision, $scale); }
    public static function float(): FloatMeta                                 { return new FloatMeta(); }

    // ── Dates ─────────────────────────────────────────────────────────────────

    public static function datetime(): DatetimeMeta  { return new DatetimeMeta(); }
    public static function date(): DateMeta          { return new DateMeta(); }
    public static function timestamp(): TimestampMeta { return new TimestampMeta(); }
    public static function createdAt(): CreatedAtMeta { return new CreatedAtMeta(); }
    public static function updatedAt(): UpdatedAtMeta { return new UpdatedAtMeta(); }
    public static function deletedAt(): DeletedAtMeta { return new DeletedAtMeta(); }

    // ── Constraints ───────────────────────────────────────────────────────────

    public static function nullable(): NullableMeta                    { return new NullableMeta(); }
    public static function unique(?string $name = null): UniqueMeta    { return new UniqueMeta($name); }
    public static function index(?string $name = null): IndexMeta      { return new IndexMeta($name); }
    public static function hidden(): HiddenMeta                        { return new HiddenMeta(); }
    public static function default(mixed $value): DefaultMeta          { return new DefaultMeta($value); }
    public static function comment(string $text): CommentMeta          { return new CommentMeta($text); }
    public static function length(int $min, int $max): LengthMeta      { return new LengthMeta($min, $max); }
    public static function precision(int $p): PrecisionMeta            { return new PrecisionMeta($p); }
    public static function scale(int $s): ScaleMeta                    { return new ScaleMeta($s); }
    public static function fillable(): FillableMeta                    { return new FillableMeta(); }
    public static function cast(string $type): CastMeta                { return new CastMeta($type); }
}

// ── Meta value objects ────────────────────────────────────────────────────────

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
final class TimestampMeta implements ColumnMeta {}
final class CreatedAtMeta implements ColumnMeta {}
final class UpdatedAtMeta implements ColumnMeta {}
final class DeletedAtMeta implements ColumnMeta {}
final class NullableMeta  implements ColumnMeta {}
final class HiddenMeta    implements ColumnMeta {}
final class FillableMeta  implements ColumnMeta {}

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
