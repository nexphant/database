<?php

namespace nexphant\Database\Streaming;

use nexphant\Database\DB;

class MysqlLoadData
{
    public static function loadFromFile(
        string $table,
        string $filePath,
        array $columns = [],
        string $connection = 'default',
        string $delimiter = ',',
        string $enclosure = '"',
        int $skipLines = 0,
    ): int {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $cols = '';
        if (!empty($columns)) {
            $cols = ' (' . implode(', ', array_map(fn($c) => "`{$c}`", $columns)) . ')';
        }

        $sql = "LOAD DATA LOCAL INFILE ?"
            . " INTO TABLE `{$table}`"
            . " FIELDS TERMINATED BY ? ENCLOSED BY ?"
            . " LINES TERMINATED BY '\\n'"
            . ($skipLines > 0 ? " IGNORE {$skipLines} LINES" : '')
            . $cols;

        DB::execute($sql, [$filePath, $delimiter, $enclosure], $connection);

        // Return affected rows from last query
        $result = DB::query("SELECT ROW_COUNT() as cnt", [], $connection);
        return (int) ($result[0]['cnt'] ?? 0);
    }

    public static function loadFromArray(
        string $table,
        array $columns,
        array $rows,
        string $connection = 'default',
        int $chunkSize = 5000,
    ): int {
        $tmpFile = tempnam(sys_get_temp_dir(), 'nexphant_load_');
        if (!$tmpFile) {
            throw new \RuntimeException('Failed to create temp file');
        }

        try {
            $fp = fopen($tmpFile, 'w');
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($fp, $line);
            }
            fclose($fp);

            return self::loadFromFile($table, $tmpFile, $columns, $connection);
        } finally {
            @unlink($tmpFile);
        }
    }
}
