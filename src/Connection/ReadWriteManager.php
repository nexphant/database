<?php

namespace nexphant\Database\Connection;

use nexphant\Database\DB;
use nexphant\Database\Drivers\DriverInterface;
use nexphant\Database\Drivers\DriverResult;

class ReadWriteManager
{
    private static array $configs = [];
    private static array $writers = [];
    private static array $readers = [];
    private static int $readerIndex = 0;

    public static function configure(string $name, array $config): void
    {
        self::$configs[$name] = $config;
    }

    public static function writer(string $name = 'default'): DriverInterface
    {
        if (!isset(self::$writers[$name])) {
            $config = self::$configs[$name] ?? [];
            $writeConfig = array_merge($config, $config['write'] ?? []);
            unset($writeConfig['read'], $writeConfig['write']);
            self::$writers[$name] = DB::connect($writeConfig, "{$name}_write");
        }
        return self::$writers[$name];
    }

    public static function reader(string $name = 'default'): DriverInterface
    {
        if (!isset(self::$readers[$name])) {
            $config = self::$configs[$name] ?? [];
            $readConfigs = $config['read'] ?? [];
            if (empty($readConfigs)) {
                return self::writer($name);
            }
            // Support multiple read replicas
            if (isset($readConfigs[0])) {
                foreach ($readConfigs as $i => $readConfig) {
                    $merged = array_merge($config, $readConfig);
                    unset($merged['read'], $merged['write']);
                    self::$readers["{$name}_{$i}"] = DB::connect($merged, "{$name}_read_{$i}");
                }
            } else {
                $merged = array_merge($config, $readConfigs);
                unset($merged['read'], $merged['write']);
                self::$readers["{$name}_0"] = DB::connect($merged, "{$name}_read_0");
            }
        }

        // Round-robin reader selection
        $readerKeys = array_keys(array_filter(self::$readers, fn($k) => str_starts_with($k, "{$name}_"), ARRAY_FILTER_USE_KEY));
        if (empty($readerKeys)) {
            return self::writer($name);
        }
        $key = $readerKeys[self::$readerIndex % count($readerKeys)];
        self::$readerIndex++;
        return self::$readers[$key];
    }

    public static function query(string $sql, array $params = [], string $name = 'default'): DriverResult
    {
        $conn = self::isWriteQuery($sql) ? self::writer($name) : self::reader($name);
        return $conn->query($sql, $params);
    }

    public static function execute(string $sql, array $params = [], string $name = 'default'): DriverResult
    {
        return self::writer($name)->execute($sql, $params);
    }

    public static function closeAll(): void
    {
        foreach (self::$writers as $conn) {
            $conn->close();
        }
        foreach (self::$readers as $conn) {
            $conn->close();
        }
        self::$writers = [];
        self::$readers = [];
        self::$configs = [];
    }

    public static function stats(): array
    {
        return [
            'writers' => count(self::$writers),
            'readers' => count(self::$readers),
            'connections' => array_keys(self::$configs),
        ];
    }

    private static function isWriteQuery(string $sql): bool
    {
        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n(") ?: '');
        return in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE'], true);
    }
}
