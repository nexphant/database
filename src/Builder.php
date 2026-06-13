<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nexph\Database;

/**
 * Ultra-thin builder alias over QueryBuilder.
 * No metadata, no reflection, no transforms.
 * Use DB::table() directly for identical behavior.
 */
class Builder
{
    private string $table;
    private string $connection;

    private function __construct(string $table, string $connection = 'default')
    {
        $this->table = $table;
        $this->connection = $connection;
    }

    public static function table(string $table, string $connection = 'default'): self
    {
        return new self($table, $connection);
    }

    public function query(): QueryBuilder
    {
        return (new QueryBuilder($this->table))->connection($this->connection);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->query()->{$method}(...$args);
    }

    public function find(mixed $id): ?array
    {
        return $this->query()->where('id', '=', $id)->first();
    }

    public function all(): array
    {
        return $this->query()->get();
    }

    public function create(array $data): ?array
    {
        $this->query()->insert($data);
        return $this->find(DB::lastInsertId($this->connection));
    }

    public function update(mixed $id, array $data): ?array
    {
        $this->query()->where('id', '=', $id)->update($data);
        return $this->find($id);
    }

    public function delete(mixed $id): bool
    {
        return $this->query()->where('id', '=', $id)->delete();
    }
}
