<?php

namespace DrJermy\DbPull\Tests;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

class PullDatabaseTest extends TestCase
{
    private string $dumpPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // db:pull refuses to run anywhere but local.
        $app['env'] = 'local';

        $this->dumpPath = sys_get_temp_dir().'/db-pull-test-'.uniqid();

        $app['config']->set('db-pull.driver', 'mysql');
        $app['config']->set('db-pull.dump_path', $this->dumpPath);
        $app['config']->set('db-pull.cloud.server', 'prod.example.com');
        $app['config']->set('db-pull.cloud.password', 'secret');
        $app['config']->set('db-pull.cloud.database', 'main');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dumpPath)) {
            array_map('unlink', glob($this->dumpPath.'/*') ?: []);
            rmdir($this->dumpPath);
        }

        parent::tearDown();
    }

    /**
     * The whole point of --dump-only: the local database must be left alone,
     * so the dump must be the only thing that runs.
     */
    public function test_dump_only_runs_the_dump_and_nothing_else(): void
    {
        Process::fake();

        $this->artisan('db:pull', ['--dump-only' => true])->assertSuccessful();

        Process::assertRanTimes(fn (PendingProcess $process) => $this->binary($process) === 'mysqldump', 1);
        Process::assertNotRan(fn (PendingProcess $process) => $this->binary($process) === 'mysql');
    }

    public function test_dump_only_keeps_the_file_and_says_where_it_is(): void
    {
        Process::fake();

        $this->artisan('db:pull', ['--dump-only' => true])
            ->expectsOutputToContain('Dump saved to:')
            ->expectsOutputToContain('unsanitized production data')
            ->assertSuccessful();
    }

    public function test_dump_only_reports_a_failed_dump_instead_of_claiming_success(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'could not connect', exitCode: 1)]);

        $this->artisan('db:pull', ['--dump-only' => true])->assertFailed();
    }

    public function test_it_refuses_to_run_outside_local(): void
    {
        Process::fake();
        $this->app['env'] = 'production';

        $this->artisan('db:pull', ['--dump-only' => true])->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_stops_when_the_production_credentials_are_missing(): void
    {
        Process::fake();
        config()->set('db-pull.cloud.password', null);

        $this->artisan('db:pull', ['--dump-only' => true])->assertFailed();

        Process::assertNothingRan();
    }

    private function binary(PendingProcess $process): string
    {
        return ((array) $process->command)[0] ?? '';
    }
}
