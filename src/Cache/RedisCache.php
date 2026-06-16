<?php

namespace nexphant\Database\Cache;

class RedisCache
{
    private \Redis $redis;

    public function __construct(array $config)
    {
        $this->redis = new \Redis();
        $this->redis->connect(
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 6379),
            (float) ($config['timeout'] ?? 2.0)
        );
        if (isset($config['password'])) {
            $this->redis->auth($config['password']);
        }
        if (isset($config['database'])) {
            $this->redis->select((int) $config['database']);
        }
        
        // Track Redis connection
        if (class_exists('\nexphant\Core\Resource\ResourceRegistry') && class_exists('\nexphant\Runtime\Runtime') && \nexphant\Runtime\Runtime::available()) {
            \nexphant\Core\Resource\ResourceRegistry::instance()->track(
                $this->redis,
                'redis_connection',
                \nexphant\Runtime\Runtime::context()->ownerId()
            );
        }
    }

    public function get(string $key): ?array
    {
        $data = $this->redis->get($key);
        if ($data === false) {
            return null;
        }
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function set(string $key, array $value, float $ttl): void
    {
        $this->redis->setex($key, (int) ceil($ttl), json_encode($value));
    }

    public function deletePattern(string $pattern): int
    {
        $keys = $this->redis->keys($pattern);
        if (empty($keys)) {
            return 0;
        }
        return $this->redis->del($keys);
    }

    public function flush(string $prefix): void
    {
        $this->deletePattern($prefix . '*');
    }
}
