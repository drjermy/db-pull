<?php

namespace DrJermy\DbPull\Tests;

use DrJermy\DbPull\Sanitizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SanitizerTest extends TestCase
{
    /**
     * Regression: the shift_date OR group was unparenthesised, so with more
     * than one date column the preserve conditions bound to the last column
     * only and preserved rows had their dates shifted anyway.
     */
    public function test_preserved_rows_survive_every_shift_date_column(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
        });

        DB::table('events')->insert(['name' => 'keep', 'starts_at' => '2020-01-01 00:00:00', 'ends_at' => '2020-01-02 00:00:00']);

        for ($i = 0; $i < 20; $i++) {
            DB::table('events')->insert(['name' => "event {$i}", 'starts_at' => '2020-01-01 00:00:00', 'ends_at' => '2020-01-02 00:00:00']);
        }

        config()->set('db-pull.sanitize.columns', ['starts_at' => 'shift_date', 'ends_at' => 'shift_date']);
        config()->set('db-pull.sanitize.preserve', ['events' => [['name' => 'keep']]]);

        (new Sanitizer)->run();

        $kept = DB::table('events')->where('name', 'keep')->first();
        $this->assertSame('2020-01-01 00:00:00', $kept->starts_at);
        $this->assertSame('2020-01-02 00:00:00', $kept->ends_at);

        // A shift of 0 years is a legal outcome for any single row, so prove
        // the sanitizer ran by looking across all twenty of the others.
        $shifted = DB::table('events')
            ->where('name', '!=', 'keep')
            ->where('starts_at', '!=', '2020-01-01 00:00:00')
            ->count();

        $this->assertGreaterThan(0, $shifted, 'no unpreserved row was shifted');
    }

    /**
     * Regression: shift_date was missing from rulesNeedIdentifier(), so a
     * table with no id column took the bulk path and chunkById() errored on
     * the column that was not there.
     */
    public function test_shift_date_handles_a_table_with_no_id_column(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->string('uuid');
            $table->dateTime('occurred_at');
        });

        DB::table('audit_log')->insert(['uuid' => 'abc', 'occurred_at' => '2020-06-15 12:00:00']);

        config()->set('db-pull.sanitize.columns', ['occurred_at' => 'shift_date']);

        (new Sanitizer)->run();

        $row = DB::table('audit_log')->first();
        $this->assertSame('abc', $row->uuid);
        $this->assertSame('06-15 12:00:00', substr((string) $row->occurred_at, 5));
    }

    public function test_it_refuses_to_sanitize_a_different_database(): void
    {
        config()->set('db-pull.local.database', 'some_other_database');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db-pull restored into [some_other_database]');

        (new Sanitizer)->run();
    }

    public function test_preserve_rules_exclude_rows_from_bulk_updates(): void
    {
        $this->makeUsers();

        config()->set('db-pull.sanitize.columns', ['notes' => 'null']);
        config()->set('db-pull.sanitize.preserve', ['users' => [['email' => 'admin@example.com']]]);

        (new Sanitizer)->run();

        $this->assertSame('keep me', DB::table('users')->where('email', 'admin@example.com')->value('notes'));
        $this->assertNull(DB::table('users')->where('email', 'someone@example.com')->value('notes'));
    }

    public function test_it_leaves_skipped_tables_alone(): void
    {
        $this->makeUsers();

        config()->set('db-pull.sanitize.columns', ['notes' => 'null']);
        config()->set('db-pull.sanitize.skip_tables', ['users']);

        (new Sanitizer)->run();

        $this->assertSame(2, DB::table('users')->whereNotNull('notes')->count());
    }

    public function test_a_table_override_can_unset_a_global_rule(): void
    {
        $this->makeUsers();

        config()->set('db-pull.sanitize.columns', ['notes' => 'null']);
        config()->set('db-pull.sanitize.tables', ['users' => ['notes' => null]]);

        (new Sanitizer)->run();

        $this->assertSame(2, DB::table('users')->whereNotNull('notes')->count());
    }

    public function test_seed_records_are_upserted_after_sanitization(): void
    {
        $this->makeUsers();

        config()->set('db-pull.sanitize.columns', ['notes' => 'null']);
        config()->set('db-pull.sanitize.seed', [
            'users' => [
                'key' => 'email',
                'values' => ['email' => 'me@example.com', 'notes' => 'seeded'],
            ],
        ]);

        (new Sanitizer)->run();

        $this->assertSame('seeded', DB::table('users')->where('email', 'me@example.com')->value('notes'));
        $this->assertSame(3, DB::table('users')->count());
    }

    public function test_disabling_sanitization_leaves_the_data_untouched(): void
    {
        $this->makeUsers();

        config()->set('db-pull.sanitize.enabled', false);
        config()->set('db-pull.sanitize.columns', ['notes' => 'null']);

        (new Sanitizer)->run();

        $this->assertSame(2, DB::table('users')->whereNotNull('notes')->count());
    }

    private function makeUsers(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('notes')->nullable();
        });

        DB::table('users')->insert([
            ['email' => 'admin@example.com', 'notes' => 'keep me'],
            ['email' => 'someone@example.com', 'notes' => 'scrub me'],
        ]);
    }
}
