<?php

namespace DrJermy\DbPull\Drivers;

interface Driver
{
    /**
     * Return the command array for dumping a remote database to a file.
     *
     * @return array<int, string>
     */
    public function dumpCommand(string $connectionString, string $dumpFile): array;

    /**
     * Return the command array for restoring a dump file to a database.
     *
     * @return array<int, string>
     */
    public function restoreCommand(string $connectionString, string $dumpFile): array;

    /**
     * Return the command array for resetting a database (dropping all data).
     *
     * @return array<int, string>
     */
    public function resetCommand(string $connectionString): array;

    /**
     * Build a connection string for a Laravel Cloud remote database.
     *
     * Passwords are deliberately absent: they travel through the process
     * environment instead. See environment().
     */
    public function remoteConnectionString(string $server, string $username, string $database): string;

    /**
     * Build a connection string or arguments for the local database.
     *
     * A null $port means "use this driver's default port".
     */
    public function localConnectionString(string $database, string $username, ?int $port = null): string;

    /**
     * Environment variables that pass the password to this driver's CLI tools.
     *
     * An empty password returns an empty array, leaving the variable unset.
     *
     * @return array<string, string>
     */
    public function environment(string $password): array;

    /**
     * The file extension to use for dump files.
     */
    public function dumpExtension(): string;
}
