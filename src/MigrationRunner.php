<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Database;

class MigrationRunner
{
    private string $path;
    private string $table = 'migrations';

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        DB::execute("CREATE TABLE IF NOT EXISTS {$this->table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL,
            batch INTEGER NOT NULL,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function up(?int $steps = null): array
    {
        $pending = $this->pending();
        if (empty($pending)) {
            return ['message' => 'Nothing to migrate'];
        }

        if ($steps) {
            $pending = array_slice($pending, 0, $steps);
        }

        $batch = $this->nextBatch();
        $migrated = [];

        DB::transaction(function () use ($pending, $batch, &$migrated): void {
            foreach ($pending as $file) {
                $migration = require $file;
                if (isset($migration['up']) && is_callable($migration['up'])) {
                    $migration['up']();
                }

                $name = basename($file, '.php');
                DB::execute("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)", [$name, $batch]);
                $migrated[] = $name;
            }
        });

        return ['migrated' => $migrated, 'batch' => $batch];
    }

    public function down(?int $steps = 1): array
    {
        $ran = $this->ran();
        if (empty($ran)) {
            return ['message' => 'Nothing to rollback'];
        }

        $toRollback = array_slice(array_reverse($ran), 0, $steps);
        $rolledBack = [];

        DB::transaction(function () use ($toRollback, &$rolledBack): void {
            foreach ($toRollback as $name) {
                $file = $this->path . '/' . $name . '.php';
                if (!file_exists($file))
                    continue;

                $migration = require $file;
                if (isset($migration['down']) && is_callable($migration['down'])) {
                    $migration['down']();
                }

                DB::execute("DELETE FROM {$this->table} WHERE migration = ?", [$name]);
                $rolledBack[] = $name;
            }
        });

        return ['rolled_back' => $rolledBack];
    }

    public function reset(): array
    {
        return $this->down(count($this->ran()));
    }

    public function refresh(): array
    {
        $reset = $this->reset();
        $up = $this->up();
        return ['reset' => $reset, 'migrated' => $up];
    }

    public function status(): array
    {
        $ran = $this->ran();
        $all = $this->all();
        $pending = array_diff($all, $ran);

        return [
            'ran' => $ran,
            'pending' => array_values($pending),
            'total' => count($all),
        ];
    }

    public function pending(): array
    {
        $ran = $this->ran();
        $files = glob($this->path . '/*.php') ?: [];
        sort($files);

        return array_filter($files, function ($file) use ($ran) {
            return !in_array(basename($file, '.php'), $ran);
        });
    }

    private function ran(): array
    {
        $result = DB::query("SELECT migration FROM {$this->table} ORDER BY id");
        return array_column($result, 'migration');
    }

    private function all(): array
    {
        $files = glob($this->path . '/*.php') ?: [];
        return array_map(fn($f) => basename($f, '.php'), $files);
    }

    private function nextBatch(): int
    {
        $result = DB::query("SELECT MAX(batch) as batch FROM {$this->table}");
        return ($result[0]['batch'] ?? 0) + 1;
    }

    public function create(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $this->path . '/' . $filename;

        $template = <<<'PHP'
<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
// Migration: {{NAME}}
// Created: {{DATE}}

use Nexph\Database\DB;

return [
    'up' => function () {
        DB::execute("
            CREATE TABLE IF NOT EXISTS {{TABLE}} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    },

    'down' => function () {
        DB::execute("DROP TABLE IF EXISTS {{TABLE}}");
    },
];
PHP;

        $table = preg_replace('/^create_|_table$/', '', $name);
        $content = str_replace(
            ['{{NAME}}', '{{DATE}}', '{{TABLE}}'],
            [$name, date('Y-m-d H:i:s'), $table],
            $template
        );

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }

        file_put_contents($filepath, $content);
        return $filename;
    }
}
