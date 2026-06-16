<?php

namespace Nexphant\Database\Cache;

class PreparedStatementCache
{
    private array $cache = [];
    private array $accessOrder = [];
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(
        private int $maxSize = 256,
    ) {
    }

    public function get(string $sql): mixed
    {
        $key = $this->key($sql);
        if (isset($this->cache[$key])) {
            $this->hits++;
            $this->touch($key);
            return $this->cache[$key]['stmt'];
        }
        $this->misses++;
        return null;
    }

    public function put(string $sql, mixed $stmt): void
    {
        $key = $this->key($sql);
        if (isset($this->cache[$key])) {
            $this->cache[$key]['stmt'] = $stmt;
            $this->touch($key);
            return;
        }
        if (count($this->cache) >= $this->maxSize) {
            $this->evict();
        }
        $this->cache[$key] = ['sql' => $sql, 'stmt' => $stmt, 'created' => time()];
        $this->accessOrder[$key] = microtime(true);
    }

    public function has(string $sql): bool
    {
        return isset($this->cache[$this->key($sql)]);
    }

    public function remove(string $sql): void
    {
        $key = $this->key($sql);
        if (isset($this->cache[$key])) {
            $this->closeStmt($this->cache[$key]['stmt']);
            unset($this->cache[$key], $this->accessOrder[$key]);
        }
    }

    public function clear(): void
    {
        foreach ($this->cache as $entry) {
            $this->closeStmt($entry['stmt']);
        }
        $this->cache = [];
        $this->accessOrder = [];
    }

    public function stats(): array
    {
        return [
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => ($this->hits + $this->misses) > 0
                ? round($this->hits / ($this->hits + $this->misses) * 100, 2)
                : 0.0,
        ];
    }

    private function key(string $sql): string
    {
        return sha1($sql);
    }

    private function touch(string $key): void
    {
        $this->accessOrder[$key] = microtime(true);
    }

    private function evict(): void
    {
        asort($this->accessOrder);
        $lruKey = array_key_first($this->accessOrder);
        if ($lruKey !== null) {
            $this->closeStmt($this->cache[$lruKey]['stmt']);
            unset($this->cache[$lruKey], $this->accessOrder[$lruKey]);
        }
    }

    private function closeStmt(mixed $stmt): void
    {
        if ($stmt instanceof \mysqli_stmt) {
            $stmt->close();
        } elseif ($stmt instanceof \SQLite3Stmt) {
            $stmt->close();
        }
        // PDOStatement and pg resources don't need explicit close
    }
}
