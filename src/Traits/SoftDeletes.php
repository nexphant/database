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

use Nexphant\Database\DB;

/**
 * SoftDeletes — adds deleted_at soft-delete support.
 */
trait SoftDeletes
{
    public ?string $deleted_at = null;

    protected static string $deletedAtColumn = 'deleted_at';

    public function softDelete(): int
    {
        $pk  = static::$primaryKey;
        $id  = $this->{$pk} ?? null;
        if ($id === null) {
            throw new \RuntimeException('Cannot soft-delete model without primary key value');
        }
        $now = date('Y-m-d H:i:s');
        $this->{static::$deletedAtColumn} = $now;
        return DB::table(static::getTable())
            ->where($pk, '=', $id)
            ->update([static::$deletedAtColumn => $now]);
    }

    public function restore(): int
    {
        $pk = static::$primaryKey;
        $id = $this->{$pk} ?? null;
        if ($id === null) {
            throw new \RuntimeException('Cannot restore model without primary key value');
        }
        $this->{static::$deletedAtColumn} = null;
        return DB::table(static::getTable())
            ->where($pk, '=', $id)
            ->update([static::$deletedAtColumn => null]);
    }

    public function isDeleted(): bool
    {
        return $this->{static::$deletedAtColumn} !== null;
    }

    public static function withTrashed(): \Nexphant\Database\QueryBuilder
    {
        return static::query();
    }

    public static function onlyTrashed(): \Nexphant\Database\QueryBuilder
    {
        return static::query()->whereNotNull(static::$deletedAtColumn);
    }
}
