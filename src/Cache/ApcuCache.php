<?php

namespace Nexphant\Database\Cache;

class ApcuCache
{
    public function get(string $key): ?array
    {
        if (!function_exists('apcu_fetch')) {
            return null;
        }
        $success = false;
        $data = apcu_fetch($key, $success);
        if (!$success) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    public function set(string $key, array $value, float $ttl): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }
        apcu_store($key, $value, (int) ceil($ttl));
    }

    public function deletePattern(string $pattern): int
    {
        if (!function_exists('apcu_delete') || !class_exists(\APCUIterator::class)) {
            return 0;
        }
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $iterator = new \APCUIterator($regex);
        $count = 0;
        foreach ($iterator as $item) {
            apcu_delete($item['key']);
            $count++;
        }
        return $count;
    }

    public function flush(string $prefix): void
    {
        $this->deletePattern($prefix . '*');
    }
}
