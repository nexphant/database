<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Database;

use Nexph\Database\DB;

class Migration {
    private static function validate(string $name): string {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \Exception("Invalid identifier: $name");
        }
        return $name;
    }

    private static function quote(string $name): string {
        return "`$name`"; // Simplified for MySQL/SQLite
    }

    public static function sync(array $meta): void {
        $table = self::validate($meta['table']);
        $fields = $meta['fields'];
        $indexes = $meta['indexes'] ?? [];
        $hasId = false;
        $idField = null;
        foreach ($fields as $field) {
            if ($field['name'] === 'id') {
                $hasId = true;
                $idField = $field;
                break;
            }
        }

        $columns = [];
        if (!$hasId) {
            $columns[] = "`id` INTEGER PRIMARY KEY AUTOINCREMENT";
        }

        foreach ($fields as $field) {
            if (empty(trim($field['name']))) continue;
            
            $name = self::validate($field['name']);
            $sqlType = self::getSqlType($field['type'] ?? 'TEXT', $field['length'] ?? '');
            $col = "`{$name}` {$sqlType}";
            
            $isPk = !empty($field['primary']);
            $isAi = !empty($field['autoincrement']);

            if ($isPk) $col .= ' PRIMARY KEY';
            if ($isAi && in_array(strtoupper($field['type'] ?? ''), ['INTEGER','BIGINT','SMALLINT','TINYINT']) && $isPk) {
                $col .= ' AUTOINCREMENT';
            }

            if (empty($field['nullable'])) $col .= ' NOT NULL';
            if (!empty($field['unique']) && !$isPk) $col .= ' UNIQUE';
            
            if (isset($field['default']) && $field['default'] !== '') {
                $def = $field['default'];
                $upperDef = strtoupper($def);
                if ($upperDef === 'NULL') {
                    $col .= ' DEFAULT NULL';
                } elseif (is_numeric($def)) {
                    $col .= " DEFAULT {$def}";
                } elseif (in_array($upperDef, ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'])) {
                    $col .= " DEFAULT {$upperDef}";
                } else {
                    $safeDef = str_replace("'", "''", $def);
                    $col .= " DEFAULT '{$safeDef}'";
                }
            }
            $columns[] = $col;
        }

        $foreignKeys = [];
        foreach ($fields as $field) {
            if (!empty($field['references']['table'])) {
                $refTable = self::validate($field['references']['table']);
                $refCol = !empty($field['references']['column']) ? self::validate($field['references']['column']) : 'id';
                $foreignKeys[] = "FOREIGN KEY(`{$field['name']}`) REFERENCES `{$refTable}`(`{$refCol}`) ON DELETE CASCADE";
            }
        }

        $allDefinitions = array_merge($columns, $foreignKeys);
        
        try {
            $dbFields = DB::query("PRAGMA table_info(`{$table}`)");
            $exists = !empty($dbFields);
        } catch (\Exception $e) {
            $exists = false;
        }

        if (!$exists) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (" . implode(', ', $allDefinitions) . ")";
            DB::query($sql);
            
            foreach ($indexes as $index) {
                $indexName = self::validate($index['name']);
                $indexCols = array_map(fn($c) => "`" . self::validate($c) . "`", $index['columns']);
                $unique = !empty($index['unique']) ? 'UNIQUE' : '';
                $indexSql = "CREATE {$unique} INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` (" . implode(', ', $indexCols) . ")";
                DB::query($indexSql);
            }
        } else {
            $isSynced = self::isSynced($table, $meta);
            if (!$isSynced) {
                // Table rebuild for SQLite to handle type/constraint changes
                try {
                    DB::query("PRAGMA foreign_keys = OFF");
                    DB::query("BEGIN TRANSACTION");
                    
                    $tempTable = "{$table}_old_" . time();
                    DB::query("ALTER TABLE `{$table}` RENAME TO `{$tempTable}`");
                    
                    $createSql = "CREATE TABLE `{$table}` (" . implode(', ', $allDefinitions) . ")";
                    DB::query($createSql);
                    
                    $dbFields = DB::query("PRAGMA table_info(`{$tempTable}`)");
                    $oldCols = array_map(fn($f) => "`{$f['name']}`", $dbFields);
                    $newCols = [];
                    $metaCols = array_map(fn($f) => $f['name'], $meta['fields']);
                    if (!$hasId) $metaCols[] = 'id';
                    
                    foreach ($dbFields as $f) {
                        if (in_array($f['name'], $metaCols)) {
                            $newCols[] = "`{$f['name']}`";
                        }
                    }
                    
                    if (!empty($newCols)) {
                        $colsStr = implode(', ', $newCols);
                        $selectCols = [];
                        foreach ($newCols as $col) {
                            $colName = trim($col, '`');
                            $field = null;
                            foreach ($meta['fields'] as $f) {
                                if ($f['name'] === $colName) {
                                    $field = $f;
                                    break;
                                }
                            }
                            if ($field && empty($field['nullable']) && isset($field['default']) && $field['default'] !== '') {
                                $def = $field['default'];
                                $upperDef = strtoupper($def);
                                if ($upperDef === 'NULL') {
                                    $selectCols[] = "COALESCE($col, NULL)";
                                } elseif (is_numeric($def)) {
                                    $selectCols[] = "COALESCE($col, $def)";
                                } elseif (in_array($upperDef, ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'])) {
                                    $selectCols[] = "COALESCE($col, $upperDef)";
                                } else {
                                    $safeDef = str_replace("'", "''", $def);
                                    $selectCols[] = "COALESCE($col, '$safeDef')";
                                }
                            } elseif ($field && empty($field['nullable'])) {
                                $type = strtoupper($field['type'] ?? 'TEXT');
                                if (in_array($type, ['INTEGER', 'BIGINT', 'SMALLINT', 'TINYINT'])) {
                                    $selectCols[] = "COALESCE($col, 0)";
                                } elseif (in_array($type, ['REAL', 'FLOAT', 'DOUBLE', 'DECIMAL'])) {
                                    $selectCols[] = "COALESCE($col, 0.0)";
                                } else {
                                    $selectCols[] = "COALESCE($col, '')";
                                }
                            } else {
                                $selectCols[] = $col;
                            }
                        }
                        $selectStr = implode(', ', $selectCols);
                        DB::query("INSERT INTO `{$table}` ({$colsStr}) SELECT {$selectStr} FROM `{$tempTable}`");
                    }
                    
                    DB::query("DROP TABLE `{$tempTable}`");
                    DB::query("COMMIT");
                    DB::query("PRAGMA foreign_keys = ON");
                    
                    foreach ($indexes as $index) {
                        $indexName = self::validate($index['name']);
                        $indexCols = array_map(fn($c) => "`" . self::validate($c) . "`", $index['columns']);
                        $unique = !empty($index['unique']) ? 'UNIQUE' : '';
                        $indexSql = "CREATE {$unique} INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` (" . implode(', ', $indexCols) . ")";
                        try {
                            DB::query($indexSql);
                        } catch (\Exception $e) {
                        }
                    }
                } catch (\Exception $e) {
                    DB::query("ROLLBACK");
                    DB::query("PRAGMA foreign_keys = ON");
                    throw $e;
                }
            }
        }
    }

    public static function isSynced(string $table, array $meta): bool {
        try {
            $dbFields = DB::query("PRAGMA table_info(`{$table}`)");
            if (empty($dbFields)) return false;
            
            $dbFieldMap = [];
            foreach ($dbFields as $f) {
                $dbFieldMap[$f['name']] = [
                    'type' => strtoupper($f['type']),
                    'notnull' => (bool)$f['notnull']
                ];
            }

            $hasIdMeta = false;
            foreach ($meta['fields'] as $field) {
                $name = $field['name'];
                if (empty($name)) continue;
                if ($name === 'id') $hasIdMeta = true;

                if (!isset($dbFieldMap[$name])) return false;
                
                $expectedType = self::getSqlType($field['type'] ?? 'TEXT', $field['length'] ?? '');
                if ($dbFieldMap[$name]['type'] !== $expectedType) return false;
                
                $expectedNotNull = empty($field['nullable']);
                if ($dbFieldMap[$name]['notnull'] !== $expectedNotNull) return false;
            }

            // Migration::sync always ensures an 'id' column exists with type INTEGER if not specified
            if (!$hasIdMeta) {
                if (!isset($dbFieldMap['id'])) return false;
                if ($dbFieldMap['id']['type'] !== 'INTEGER') return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function getSqlType(string $type, string $length = ''): string {
        $type = strtoupper($type);
        return match ($type) {
            'VARCHAR' => $length ? "VARCHAR(" . (int)$length . ")" : 'VARCHAR(255)',
            'CHAR' => $length ? "CHAR(" . (int)$length . ")" : 'CHAR(1)',
            'DECIMAL' => $length ? "DECIMAL(" . str_replace(' ', '', $length) . ")" : 'DECIMAL(10,2)',
            'INTEGER','BIGINT','SMALLINT','TINYINT' => 'INTEGER',
            'REAL','FLOAT','DOUBLE' => 'REAL',
            'BOOLEAN' => 'INTEGER',
            'DATE' => 'DATE',
            'DATETIME' => 'DATETIME',
            'TIMESTAMP' => 'TIMESTAMP',
            'TIME' => 'TIME',
            'BLOB' => 'BLOB',
            'JSON' => 'TEXT',
            'ENUM' => 'TEXT',
            'UUID' => 'TEXT',
            'TEXT' => 'TEXT',
            default => 'TEXT'
        };
    }
}
