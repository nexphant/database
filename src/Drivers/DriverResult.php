<?php

namespace nexphant\Database\Drivers;

final class DriverResult
{
    public function __construct(
        public array $rows = [],
        public int $affectedRows = 0,
        public ?string $insertId = null,
        public float $durationMs = 0.0,
    ) {
    }
}
