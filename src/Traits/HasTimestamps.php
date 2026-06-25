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
 * HasTimestamps — auto-manages created_at / updated_at columns.
 */
trait HasTimestamps
{
    public ?string $created_at = null;
    public ?string $updated_at = null;

    protected static string $createdAtColumn = 'created_at';
    protected static string $updatedAtColumn = 'updated_at';

    public function touchCreated(): void
    {
        $this->{static::$createdAtColumn} = date('Y-m-d H:i:s');
        $this->{static::$updatedAtColumn} = date('Y-m-d H:i:s');
    }

    public function touchUpdated(): void
    {
        $this->{static::$updatedAtColumn} = date('Y-m-d H:i:s');
    }
}
