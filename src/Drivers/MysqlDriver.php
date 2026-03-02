<?php

namespace DrJermy\DbPull\Drivers;

class MysqlDriver implements Driver
{
    /**
     * Connection strings for MySQL are stored as JSON-encoded arrays
     * since MySQL CLI tools use separate flags rather than a URI.
     *
     * @return array<int, string>
     */
    public function dumpCommand(string $connectionString, string $dumpFile): array
    {
        $args = $this->parseConnectionArgs($connectionString);

        return array_merge(
            ['mysqldump'],
            $args,
            ['--single-transaction', '--routines', '--triggers', '--result-file='.$dumpFile],
        );
    }

    public function restoreCommand(string $connectionString, string $dumpFile): array
    {
        $args = $this->parseConnectionArgs($connectionString);

        return array_merge(
            ['mysql'],
            $args,
            ['-e', "source {$dumpFile}"],
        );
    }

    public function resetCommand(string $connectionString): array
    {
        $args = $this->parseConnectionArgs($connectionString);
        $database = $this->extractDatabase($connectionString);

        return array_merge(
            ['mysql'],
            $this->parseConnectionArgs($connectionString, withDatabase: false),
            ['-e', "DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}`;"],
        );
    }

    public function remoteConnectionString(string $server, string $username, string $password, string $database): string
    {
        return json_encode([
            '--host='.$server,
            '--port=3306',
            '--user='.$username,
            '--password='.$password,
            $database,
        ]);
    }

    public function localConnectionString(string $database, string $username, string $password = ''): string
    {
        $args = [
            '--host=127.0.0.1',
            '--port=3306',
            '--user='.$username,
        ];

        if ($password !== '') {
            $args[] = '--password='.$password;
        }

        $args[] = $database;

        return json_encode($args);
    }

    public function dumpExtension(): string
    {
        return '.sql';
    }

    /**
     * @return array<int, string>
     */
    private function parseConnectionArgs(string $connectionString, bool $withDatabase = true): array
    {
        $args = json_decode($connectionString, true);

        if (! $withDatabase) {
            // Remove the last element (database name) which has no -- prefix
            return array_filter($args, fn (string $arg) => str_starts_with($arg, '--'));
        }

        return $args;
    }

    private function extractDatabase(string $connectionString): string
    {
        $args = json_decode($connectionString, true);

        // Database is the last arg without a -- prefix
        foreach (array_reverse($args) as $arg) {
            if (! str_starts_with($arg, '--')) {
                return $arg;
            }
        }

        return '';
    }
}
