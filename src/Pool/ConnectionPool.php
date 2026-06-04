<?php

namespace Nexph\Database\Pool;

use Nexph\Database\DB;
use Nexph\Database\Drivers\DriverInterface;

class ConnectionPool
{
    private array $idle = [];
    private array $active = [];
    private array $waitQueue = [];
    private int $created = 0;
    private float $lastCleanup = 0;
    private int $waitCount = 0;
    private int $timeoutCount = 0;

    public function __construct(
        private string $name,
        private array $config,
        private int $maxSize = 10,
        private int $minIdle = 2,
        private int $idleTimeout = 300,
        private int $maxLifetime = 3600,
        private int $healthCheckInterval = 30,
        private float $acquireTimeout = 5.0,
        private int $maxWaitQueue = 50,
    ) {
        $this->lastCleanup = microtime(true);
    }

    public function get(): DriverInterface
    {
        $this->cleanup();

        while ($entry = array_pop($this->idle)) {
            if ($this->isExpired($entry)) {
                $this->destroy($entry);
                continue;
            }
            if (!$this->isHealthy($entry)) {
                $this->destroy($entry);
                continue;
            }
            $entry['acquired_at'] = microtime(true);
            $this->active[spl_object_id($entry['conn'])] = $entry;
            return $entry['conn'];
        }

        if ($this->created < $this->maxSize) {
            return $this->createConnection();
        }

        // Wait queue with timeout
        return $this->waitForConnection();
    }

    public function release(DriverInterface $conn): void
    {
        $id = spl_object_id($conn);
        if (!isset($this->active[$id])) {
            return;
        }
        $entry = $this->active[$id];
        unset($this->active[$id]);
        $entry['released_at'] = microtime(true);

        // Wake up waiting Fiber if any
        if (!empty($this->waitQueue)) {
            $waiter = array_shift($this->waitQueue);
            $entry['acquired_at'] = microtime(true);
            $this->active[spl_object_id($entry['conn'])] = $entry;
            $waiter['fiber']->resume($entry['conn']);
            return;
        }

        $this->idle[] = $entry;
    }

    public function destroy(array $entry): void
    {
        $entry['conn']->close();
        $this->created--;
        $id = spl_object_id($entry['conn']);
        unset($this->active[$id]);
    }

    public function closeAll(): void
    {
        // Reject all waiters
        foreach ($this->waitQueue as $waiter) {
            if ($waiter['fiber']->isSuspended()) {
                $waiter['fiber']->throw(new \RuntimeException("Pool [{$this->name}] closed"));
            }
        }
        $this->waitQueue = [];

        foreach ($this->idle as $entry) {
            $entry['conn']->close();
        }
        foreach ($this->active as $entry) {
            $entry['conn']->close();
        }
        $this->idle = [];
        $this->active = [];
        $this->created = 0;
    }

    public function stats(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->config['driver'] ?? 'unknown',
            'total' => $this->created,
            'active' => count($this->active),
            'idle' => count($this->idle),
            'max_size' => $this->maxSize,
            'min_idle' => $this->minIdle,
            'waiting' => count($this->waitQueue),
            'max_wait_queue' => $this->maxWaitQueue,
            'acquire_timeout' => $this->acquireTimeout,
            'wait_count' => $this->waitCount,
            'timeout_count' => $this->timeoutCount,
        ];
    }

    public function warmup(): void
    {
        while (count($this->idle) < $this->minIdle && $this->created < $this->maxSize) {
            $conn = $this->createConnection();
            $id = spl_object_id($conn);
            $entry = $this->active[$id];
            unset($this->active[$id]);
            $entry['released_at'] = microtime(true);
            $this->idle[] = $entry;
        }
    }

    private function waitForConnection(): DriverInterface
    {
        if (count($this->waitQueue) >= $this->maxWaitQueue) {
            throw new \RuntimeException("Pool [{$this->name}] wait queue full (max: {$this->maxWaitQueue})");
        }

        $this->waitCount++;
        $fiber = \Fiber::getCurrent();

        if (!$fiber) {
            // Not in a Fiber — spin-wait with timeout
            return $this->spinWait();
        }

        // Fiber-based wait
        $waiter = ['fiber' => $fiber, 'enqueued_at' => microtime(true)];
        $this->waitQueue[] = $waiter;

        $conn = \Fiber::suspend();
        if (!$conn instanceof DriverInterface) {
            $this->timeoutCount++;
            throw new \RuntimeException("Pool [{$this->name}] acquire timeout ({$this->acquireTimeout}s)");
        }
        return $conn;
    }

    private function spinWait(): DriverInterface
    {
        $deadline = microtime(true) + $this->acquireTimeout;

        while (microtime(true) < $deadline) {
            usleep(1000); // 1ms
            $this->cleanup();

            while ($entry = array_pop($this->idle)) {
                if ($this->isExpired($entry)) {
                    $this->destroy($entry);
                    continue;
                }
                if (!$this->isHealthy($entry)) {
                    $this->destroy($entry);
                    continue;
                }
                $entry['acquired_at'] = microtime(true);
                $this->active[spl_object_id($entry['conn'])] = $entry;
                return $entry['conn'];
            }

            if ($this->created < $this->maxSize) {
                return $this->createConnection();
            }
        }

        $this->timeoutCount++;
        throw new \RuntimeException("Pool [{$this->name}] acquire timeout ({$this->acquireTimeout}s)");
    }

    private function createConnection(): DriverInterface
    {
        $poolName = "{$this->name}_pool_{$this->created}";
        $conn = DB::connect($this->config, $poolName);
        $this->created++;
        $entry = [
            'conn' => $conn,
            'pool_name' => $poolName,
            'created_at' => microtime(true),
            'acquired_at' => microtime(true),
            'released_at' => null,
        ];
        $this->active[spl_object_id($conn)] = $entry;
        
        // Track connection with resource registry
        if (class_exists('\Nexph\Runtime\Resource\ResourceRegistry') && class_exists('\Nexph\Runtime\Runtime') && \Nexph\Runtime\Runtime::available()) {
            \Nexph\Runtime\Resource\ResourceRegistry::instance()->track(
                $conn,
                'db_connection',
                \Nexph\Runtime\Runtime::context()->ownerId()
            );
        }
        
        return $conn;
    }

    private function isExpired(array $entry): bool
    {
        $age = microtime(true) - $entry['created_at'];
        if ($age > $this->maxLifetime) {
            return true;
        }
        if ($entry['released_at'] && (microtime(true) - $entry['released_at']) > $this->idleTimeout) {
            return true;
        }
        return false;
    }

    private function isHealthy(array $entry): bool
    {
        $conn = $entry['conn'];
        try {
            $conn->query('SELECT 1', []);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cleanup(): void
    {
        $now = microtime(true);
        if (($now - $this->lastCleanup) < $this->healthCheckInterval) {
            return;
        }
        $this->lastCleanup = $now;

        $keep = [];
        foreach ($this->idle as $entry) {
            if ($this->isExpired($entry)) {
                $this->destroy($entry);
            } else {
                $keep[] = $entry;
            }
        }
        $this->idle = $keep;

        // Timeout expired waiters
        $alive = [];
        foreach ($this->waitQueue as $waiter) {
            if (($now - $waiter['enqueued_at']) > $this->acquireTimeout) {
                if ($waiter['fiber']->isSuspended()) {
                    $this->timeoutCount++;
                    $waiter['fiber']->throw(
                        new \RuntimeException("Pool [{$this->name}] acquire timeout ({$this->acquireTimeout}s)")
                    );
                }
            } else {
                $alive[] = $waiter;
            }
        }
        $this->waitQueue = $alive;
    }
}
