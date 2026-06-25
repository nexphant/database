<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Database\Traits;

/**
 * HasFillable — controls which properties can be mass-assigned.
 * HasHidden  — controls which properties are hidden from toArray().
 * HasCasts   — type-casts properties on get/set.
 */
trait HasFillable
{
    /** @var string[] Property names allowed for mass-assignment */
    protected static array $fillable = [];

    /** @var string[] Property names blocked from mass-assignment */
    protected static array $guarded = ['id'];

    /**
     * Fill model properties from an array, respecting fillable/guarded.
     */
    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            if ($this->isFillable($key)) {
                $this->{$key} = $value;
            }
        }
        return $this;
    }

    public static function make(array $data): static
    {
        return (new static())->fill($data);
    }

    protected function isFillable(string $key): bool
    {
        if (in_array($key, static::$guarded, true)) {
            return false;
        }
        return empty(static::$fillable) || in_array($key, static::$fillable, true);
    }
}

trait HasHidden
{
    /** @var string[] Properties excluded from toArray() / JSON output */
    protected static array $hidden = [];

    public function toVisible(): array
    {
        $data = $this->toArray();
        foreach (static::$hidden as $key) {
            unset($data[$key]);
        }
        return $data;
    }
}

trait HasCasts
{
    /**
     * @var array<string, string>  'property' => 'int'|'float'|'bool'|'array'|'json'|'string'
     */
    protected static array $casts = [];

    protected function castValue(string $key, mixed $value): mixed
    {
        $type = static::$casts[$key] ?? null;
        if ($type === null) return $value;

        return match ($type) {
            'int', 'integer'   => (int) $value,
            'float', 'double'  => (float) $value,
            'bool', 'boolean'  => (bool) $value,
            'string'           => (string) $value,
            'array'            => is_array($value) ? $value : (array) $value,
            'json'             => is_string($value) ? json_decode($value, true) : $value,
            default            => $value,
        };
    }

    protected function applyAllCasts(): void
    {
        foreach (static::$casts as $key => $_) {
            if (property_exists($this, $key)) {
                $this->{$key} = $this->castValue($key, $this->{$key});
            }
        }
    }
}
