<?php

namespace DrJermy\DbPull\Drivers;

class PostgresDriver implements Driver
{
    public const DEFAULT_PORT = 5432;

    public function dumpCommand(string $connectionString, string $dumpFile): array
    {
        return [
            'pg_dump',
            '--no-owner',
            '--no-acl',
            '-Fc',
            $connectionString,
            '-f',
            $dumpFile,
        ];
    }

    public function restoreCommand(string $connectionString, string $dumpFile): array
    {
        return [
            'pg_restore',
            '--no-owner',
            '--no-acl',
            '-d',
            $connectionString,
            $dumpFile,
        ];
    }

    public function resetCommand(string $connectionString): array
    {
        return [
            'psql',
            $connectionString,
            '-c',
            'DROP SCHEMA public CASCADE; CREATE SCHEMA public;',
        ];
    }

    public function remoteConnectionString(string $server, string $username, string $database): string
    {
        return "postgres://{$username}@{$server}/{$database}";
    }

    public function localConnectionString(string $database, string $username, ?int $port = null): string
    {
        $port ??= self::DEFAULT_PORT;

        return "postgres://{$username}@127.0.0.1:{$port}/{$database}";
    }

    /**
     * @return array<string, string>
     */
    public function environment(string $password): array
    {
        return $password === '' ? [] : ['PGPASSWORD' => $password];
    }

    public function dumpExtension(): string
    {
        return '.dump';
    }
}
