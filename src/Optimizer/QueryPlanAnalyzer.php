<?php

namespace nexphant\Database\Optimizer;

use nexphant\Database\DB;

class QueryPlanAnalyzer
{
    public static function explain(string $sql, array $params = [], string $connection = 'default'): array
    {
        $driver = self::detectDriver($connection);
        $explainSql = match ($driver) {
            'pgsql', 'postgres', 'postgresql' => "EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) {$sql}",
            'mysql', 'mariadb' => "EXPLAIN {$sql}",
            'sqlite' => "EXPLAIN QUERY PLAN {$sql}",
            default => "EXPLAIN {$sql}",
        };

        return DB::query($explainSql, $params, $connection);
    }

    public static function analyze(string $sql, array $params = [], string $connection = 'default'): array
    {
        $plan = self::explain($sql, $params, $connection);
        $driver = self::detectDriver($connection);

        return [
            'plan' => $plan,
            'recommendations' => self::recommend($plan, $driver, $sql),
            'driver' => $driver,
        ];
    }

    public static function suggestIndexes(string $sql, array $params = [], string $connection = 'default'): array
    {
        $plan = self::explain($sql, $params, $connection);
        $driver = self::detectDriver($connection);
        $suggestions = [];

        if ($driver === 'sqlite') {
            foreach ($plan as $row) {
                $detail = $row['detail'] ?? '';
                if (str_contains($detail, 'SCAN')) {
                    if (preg_match('/SCAN (\w+)/', $detail, $m)) {
                        $suggestions[] = [
                            'table' => $m[1],
                            'reason' => 'Full table scan detected',
                            'suggestion' => "Consider adding an index on frequently filtered columns of `{$m[1]}`",
                        ];
                    }
                }
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'])) {
            foreach ($plan as $row) {
                $type = $row['type'] ?? $row['select_type'] ?? '';
                if (in_array($type, ['ALL', 'index'])) {
                    $table = $row['table'] ?? 'unknown';
                    $suggestions[] = [
                        'table' => $table,
                        'reason' => "Scan type: {$type}",
                        'possible_keys' => $row['possible_keys'] ?? null,
                        'suggestion' => "Add index on WHERE/JOIN columns for `{$table}`",
                    ];
                }
            }
        } elseif (in_array($driver, ['pgsql', 'postgres', 'postgresql'])) {
            $jsonPlan = $plan[0]['QUERY PLAN'] ?? $plan[0][0] ?? null;
            if (is_string($jsonPlan)) {
                $decoded = json_decode($jsonPlan, true);
                if ($decoded) {
                    self::analyzePostgresPlan($decoded, $suggestions);
                }
            } elseif (is_array($jsonPlan)) {
                self::analyzePostgresPlan($jsonPlan, $suggestions);
            }
        }

        return $suggestions;
    }

    private static function analyzePostgresPlan(array $plan, array &$suggestions): void
    {
        $nodes = is_array($plan[0] ?? null) ? $plan[0] : $plan;
        $node = $nodes['Plan'] ?? $nodes;

        if (isset($node['Node Type'])) {
            if (str_contains($node['Node Type'], 'Seq Scan')) {
                $table = $node['Relation Name'] ?? 'unknown';
                $suggestions[] = [
                    'table' => $table,
                    'reason' => 'Sequential scan detected',
                    'rows' => $node['Plan Rows'] ?? null,
                    'suggestion' => "Consider adding an index for `{$table}`",
                ];
            }
        }

        foreach ($node['Plans'] ?? [] as $child) {
            self::analyzePostgresPlan([$child], $suggestions);
        }
    }

    private static function recommend(array $plan, string $driver, string $sql): array
    {
        $recommendations = [];

        if (preg_match('/SELECT\s+\*/i', $sql)) {
            $recommendations[] = 'Avoid SELECT * — specify only needed columns';
        }
        if (preg_match('/LIKE\s+[\'"]%/i', $sql)) {
            $recommendations[] = 'Leading wildcard in LIKE prevents index usage';
        }
        if (!preg_match('/LIMIT/i', $sql) && preg_match('/SELECT/i', $sql)) {
            $recommendations[] = 'Consider adding LIMIT to prevent unbounded result sets';
        }

        return $recommendations;
    }

    private static function detectDriver(string $connection): string
    {
        try {
            $stats = DB::stats($connection);
            return $stats['driver'] ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
