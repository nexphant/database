<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Database;

/**
 * QueryMetadata — records metadata about executed queries for observability/debugging.
 */
class QueryMetadata
{
    private array $log = [];

    public function record(string $sql, array $bindings, float $durationMs, string $connection = 'default'): void
    {
        $this->log[] = [
            'sql'        => $sql,
            'bindings'   => $bindings,
            'duration_ms'=> $durationMs,
            'connection' => $connection,
            'time'       => microtime(true),
        ];
    }

    public function all(): array { return $this->log; }

    public function last(): ?array { return end($this->log) ?: null; }

    public function count(): int { return count($this->log); }

    public function totalMs(): float
    {
        return array_sum(array_column($this->log, 'duration_ms'));
    }

    public function slow(float $thresholdMs = 100): array
    {
        return array_values(array_filter(
            $this->log,
            fn($q) => $q['duration_ms'] >= $thresholdMs
        ));
    }

    public function flush(): void { $this->log = []; }
}
