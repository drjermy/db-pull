<?php

namespace DrJermy\DbPull;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class Sanitizer
{
    public function run(): void
    {
        $config = config('db-pull.sanitize', []);
        $globalColumns = $config['columns'] ?? [];
        $tableOverrides = $config['tables'] ?? [];
        $skipTables = $config['skip_tables'] ?? [];
        $seeds = $config['seed'] ?? [];

        $tables = $this->getAllTables();

        foreach ($tables as $table) {
            if (in_array($table, $skipTables, true)) {
                continue;
            }

            $rules = $globalColumns;

            // Merge table-specific overrides
            if (isset($tableOverrides[$table])) {
                foreach ($tableOverrides[$table] as $column => $strategy) {
                    if ($strategy === null) {
                        unset($rules[$column]);
                    } else {
                        $rules[$column] = $strategy;
                    }
                }
            }

            if (empty($rules)) {
                continue;
            }

            $this->sanitizeTable($table, $rules);
        }

        // Upsert seed records
        foreach ($seeds as $table => $seed) {
            $key = $seed['key'];
            $values = $seed['values'];

            DB::table($table)->updateOrInsert(
                [$key => $values[$key]],
                $values,
            );
        }

        // Run optional sanitize command
        $command = config('db-pull.sanitize_command');

        if ($command) {
            Artisan::call($command);
        }
    }

    private function sanitizeTable(string $table, array $rules): void
    {
        $columns = Schema::getColumnListing($table);
        $applicableRules = array_intersect_key($rules, array_flip($columns));

        if (empty($applicableRules)) {
            return;
        }

        $sets = [];
        $dateColumns = [];

        foreach ($applicableRules as $column => $strategy) {
            if ($strategy === 'shift_date') {
                $dateColumns[] = $column;

                continue;
            }

            $set = $this->buildSetClause($column, $strategy);

            if ($set !== null) {
                $sets[] = $set;
            }
        }

        // Run bulk UPDATE for non-date strategies
        if (! empty($sets)) {
            $grammar = DB::getQueryGrammar();
            $wrappedTable = $grammar->wrapTable($table);
            $setString = implode(', ', $sets);
            DB::statement("UPDATE {$wrappedTable} SET {$setString}");
        }

        // Handle shift_date separately (needs per-row randomisation)
        if (! empty($dateColumns)) {
            $this->shiftDates($table, $dateColumns);
        }
    }

    private function buildSetClause(string $column, string $strategy): ?string
    {
        $grammar = DB::getQueryGrammar();
        $col = $grammar->wrap($column);
        $id = $grammar->wrap('id');

        $isPgsql = config('db-pull.driver', 'mysql') === 'pgsql';

        return match ($strategy) {
            'fake_email' => "{$col} = CONCAT('user', {$id}, '@example.com')",
            'fake_name' => "{$col} = CONCAT('User ', {$id})",
            'hash_random' => "{$col} = '".Hash::make('password')."'",
            'fake_phone' => $isPgsql
                ? "{$col} = CONCAT('+1555', LPAD({$id}::text, 7, '0'))"
                : "{$col} = CONCAT('+1555', LPAD({$id}, 7, '0'))",
            'null' => "{$col} = NULL",
            default => null,
        };
    }

    private function shiftDates(string $table, array $columns): void
    {
        $query = DB::table($table);

        foreach ($columns as $column) {
            $query->orWhereNotNull($column);
        }

        $query->chunkById(500, function ($rows) use ($table, $columns) {
            foreach ($rows as $row) {
                $updates = [];

                foreach ($columns as $column) {
                    if ($row->{$column} === null) {
                        continue;
                    }

                    $shift = random_int(-5, 5);
                    $updates[$column] = now()->parse($row->{$column})->addYears($shift);
                }

                if (! empty($updates)) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function getAllTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->all();
    }
}
