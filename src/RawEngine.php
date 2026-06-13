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
 * High-performance raw database operations.
 * No metadata dependency, no object hydration, no magic.
 */
class RawEngine
{
    public static function findById(string $table, mixed $id, array $fields = ['*'], array $hidden = [], array $casts = []): ?array
    {
        $selectCols = $fields === ['*'] ? '*' : implode(', ', array_map(fn($f) => "`{$f}`", $fields));
        $data = DB::query("SELECT {$selectCols} FROM `{$table}` WHERE `id` = ?", [$id]);
        if (empty($data)) {
            return null;
        }
        return self::transform($data[0], $hidden, $casts);
    }

    public static function list(string $table, array $options = []): array
    {
        $fields = $options['fields'] ?? ['*'];
        $filters = $options['filters'] ?? [];
        $limit = $options['limit'] ?? 20;
        $offset = $options['offset'] ?? 0;
        $orderBy = $options['order_by'] ?? null;
        $orderDir = $options['order_dir'] ?? 'ASC';
        $hidden = $options['hidden'] ?? [];
        $casts = $options['casts'] ?? [];

        $selectCols = $fields === ['*'] ? '*' : implode(', ', array_map(fn($f) => "`{$f}`", $fields));
        [$whereClause, $params] = self::buildWhere($filters);

        $sql = "SELECT {$selectCols} FROM `{$table}`{$whereClause}";

        if ($orderBy !== null) {
            $direction = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY `{$orderBy}` {$direction}";
        }

        $sql .= " LIMIT {$limit} OFFSET {$offset}";
        $data = DB::query($sql, $params);
        return array_map(fn($row) => self::transform($row, $hidden, $casts), $data);
    }

    public static function create(string $table, array $data, array $fillable = []): bool
    {
        if (!empty($fillable)) {
            $data = array_intersect_key($data, array_flip($fillable));
        }
        if (empty($data)) {
            return false;
        }
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        return DB::execute("INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})", array_values($data));
    }

    public static function update(string $table, mixed $id, array $data, array $fillable = []): bool
    {
        if (!empty($fillable)) {
            $data = array_intersect_key($data, array_flip($fillable));
        }
        if (empty($data)) {
            return false;
        }
        $sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
        $values = array_values($data);
        $values[] = $id;
        return DB::execute("UPDATE `{$table}` SET {$sets} WHERE `id` = ?", $values);
    }

    public static function delete(string $table, mixed $id): bool
    {
        return DB::execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
    }

    public static function count(string $table, array $filters = []): int
    {
        [$whereClause, $params] = self::buildWhere($filters);
        $result = DB::query("SELECT COUNT(*) as cnt FROM `{$table}`{$whereClause}", $params);
        return (int) ($result[0]['cnt'] ?? 0);
    }

    public static function paginate(string $table, array $options = []): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, (int) ($options['per_page'] ?? 20));
        $options['offset'] = ($page - 1) * $perPage;
        $options['limit'] = $perPage;

        $data = self::list($table, $options);
        $total = self::count($table, $options['filters'] ?? []);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    public static function query(string $sql, array $params = [], array $hidden = [], array $casts = []): array
    {
        $data = DB::query($sql, $params);
        if (empty($hidden) && empty($casts)) {
            return $data;
        }
        return array_map(fn($row) => self::transform($row, $hidden, $casts), $data);
    }

    private static function transform(array $data, array $hidden, array $casts): array
    {
        if (!empty($hidden)) {
            foreach ($hidden as $field) {
                unset($data[$field]);
            }
        }
        if (!empty($casts)) {
            foreach ($casts as $field => $type) {
                if (!array_key_exists($field, $data))
                    continue;
                $data[$field] = match ($type) {
                    'int', 'integer' => (int) $data[$field],
                    'float', 'double' => (float) $data[$field],
                    'bool', 'boolean' => (bool) $data[$field],
                    'string' => (string) $data[$field],
                    'json', 'array' => is_string($data[$field]) ? json_decode($data[$field], true) ?? [] : (array) $data[$field],
                    default => $data[$field],
                };
            }
        }
        return $data;
    }

    private static function buildWhere(array $filters): array
    {
        if (empty($filters)) {
            return ['', []];
        }
        $conditions = [];
        $params = [];
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $conditions[] = "`{$field}` IN ({$placeholders})";
                $params = array_merge($params, $value);
            } else {
                $conditions[] = "`{$field}` = ?";
                $params[] = $value;
            }
        }
        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    public static function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
