<?php

namespace Nexphant\Database\Drivers;

interface AsyncDriverInterface extends DriverInterface
{
    public function attachLoop(object $loop): void;
    public function queryAsync(string $sql, array $params = []): \Generator;
    public function executeAsync(string $sql, array $params = []): \Generator;
    public function inFlight(): int;
}
