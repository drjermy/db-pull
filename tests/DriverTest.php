<?php

namespace DrJermy\DbPull\Tests;

use DrJermy\DbPull\Drivers\Driver;
use DrJermy\DbPull\Drivers\MysqlDriver;
use DrJermy\DbPull\Drivers\PostgresDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as UnitTestCase;

class DriverTest extends UnitTestCase
{
    private const PASSWORD = 'hunter2';

    public static function drivers(): array
    {
        return [
            'mysql' => [new MysqlDriver],
            'pgsql' => [new PostgresDriver],
        ];
    }

    /**
     * The password must never reach a command line: argv is world-readable,
     * so anything here would sit in `ps` for the length of a dump.
     */
    #[DataProvider('drivers')]
    public function test_no_command_carries_the_password(Driver $driver): void
    {
        foreach ($this->commandsFor($driver) as $label => $command) {
            $argv = implode(' ', $command);

            $this->assertStringNotContainsString(self::PASSWORD, $argv, "{$label} leaks the password");
            $this->assertStringNotContainsString('password', $argv, "{$label} still passes a password flag");
        }
    }

    #[DataProvider('drivers')]
    public function test_the_password_travels_in_the_environment(Driver $driver): void
    {
        $env = $driver->environment(self::PASSWORD);

        $this->assertCount(1, $env);
        $this->assertContains(self::PASSWORD, $env);
        $this->assertContains(array_key_first($env), ['MYSQL_PWD', 'PGPASSWORD']);
    }

    #[DataProvider('drivers')]
    public function test_an_empty_password_sets_no_variable(Driver $driver): void
    {
        $this->assertSame([], $driver->environment(''));
    }

    #[DataProvider('drivers')]
    public function test_every_command_is_a_non_empty_argument_list(Driver $driver): void
    {
        foreach ($this->commandsFor($driver) as $label => $command) {
            $this->assertNotEmpty($command, "{$label} produced no command");
            $this->assertContainsOnlyString($command, "{$label} has non-string arguments");
        }
    }

    #[DataProvider('drivers')]
    public function test_the_local_port_is_honoured(Driver $driver): void
    {
        $onDefault = $driver->localConnectionString('myapp', 'root');
        $onCustom = $driver->localConnectionString('myapp', 'root', 5433);

        $this->assertStringContainsString('5433', $onCustom);
        $this->assertStringNotContainsString('5433', $onDefault);
    }

    #[DataProvider('drivers')]
    public function test_reset_targets_the_named_database(Driver $driver): void
    {
        $reset = implode(' ', $driver->resetCommand($driver->localConnectionString('myapp', 'root')));

        $this->assertStringContainsString('myapp', $reset);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function commandsFor(Driver $driver): array
    {
        $remote = $driver->remoteConnectionString('prod.example.com', 'laravel', 'main');
        $local = $driver->localConnectionString('myapp', 'root');
        $file = '/tmp/dump'.$driver->dumpExtension();

        return [
            'remote dump' => $driver->dumpCommand($remote, $file),
            'local dump' => $driver->dumpCommand($local, $file),
            'restore' => $driver->restoreCommand($local, $file),
            'reset' => $driver->resetCommand($local),
        ];
    }
}
