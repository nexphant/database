<?php

namespace Nexph\Database\Drivers;

interface DriverInterface
{
    public function connect(array $config): void;
    public function query(string $sql, array $params = []): DriverResult;
    public function execute(string $sql, array $params = []): DriverResult;
    public function lastInsertId(): string;
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function close(): void;
    public function stats(): array;
}
