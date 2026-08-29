<?php

namespace DrJermy\DbPull\Commands;

use DrJermy\DbPull\Drivers\Driver;
use DrJermy\DbPull\Drivers\MysqlDriver;
use DrJermy\DbPull\Drivers\PostgresDriver;
use DrJermy\DbPull\Sanitizer;
use Illuminate\Console\Command;
use Illuminate\Console\Prohibitable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class PullDatabase extends Command
{
    use Prohibitable;

    protected $signature = 'db:pull
                            {--keep-dump : Keep the dump file after restore}
                            {--push-to-staging : Push local database to staging (does not pull from production)}
                            {--from-dump : Restore from an existing dump file instead of pulling fresh}
                            {--clean-dumps : Delete old dump files}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Pull the production database and sanitize it, or push local to staging';

    private Driver $driver;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            error('This command can only be run in the local environment.');

            return Command::FAILURE;
        }

        $this->driver = $this->resolveDriver();

        if (! config('db-pull.local.database')) {
            error('No local database configured. Set DB_DATABASE in your .env file.');

            return Command::FAILURE;
        }

        if ($this->option('push-to-staging')) {
            if (! $this->hasStagingConfig()) {
                error('Staging database credentials are not configured.');

                return Command::FAILURE;
            }

            return $this->pushToStaging();
        }

        if ($this->option('clean-dumps')) {
            return $this->cleanDumps();
        }

        if ($this->option('from-dump')) {
            return $this->restoreFromDump();
        }

        if (! $this->option('force') && ! $this->option('keep-dump')) {
            return $this->showMenu();
        }

        return $this->pullFromProduction();
    }

    /**
     * Run one of the database CLI tools with the password in its environment.
     *
     * ponytail: MYSQL_PWD / PGPASSWORD keep the password out of the
     * world-readable process list, which is all this needs. If it ever has to
     * run on a shared host, move to an option file (--defaults-extra-file)
     * to hide it from /proc/<pid>/environ too.
     *
     * @param  array<int, string>  $command
     */
    private function runProcess(array $command, string $password): ProcessResult
    {
        return Process::env($this->driver->environment($password))
            ->timeout(300)
            ->run($command);
    }

    private function localPassword(): string
    {
        return (string) config('db-pull.local.password', '');
    }

    private function resolveDriver(): Driver
    {
        $driver = config('db-pull.driver', 'mysql');

        return match ($driver) {
            'pgsql' => new PostgresDriver,
            default => new MysqlDriver,
        };
    }

    private function showMenu(): int
    {
        $dumps = $this->getDumpFiles();
        $hasDumps = $dumps->isNotEmpty();

        $options = [
            'pull' => 'Pull fresh from production',
        ];

        if ($hasDumps) {
            $options['restore'] = "Restore from existing dump ({$dumps->count()} available)";
            $options['clean'] = 'Clean up old dumps';
        }

        if ($this->hasStagingConfig()) {
            $options['staging'] = 'Push local to staging';
        }

        $action = select(
            label: 'What would you like to do?',
            options: $options,
        );

        return match ($action) {
            'pull' => $this->pullFromProduction(),
            'restore' => $this->restoreFromDump(),
            'clean' => $this->cleanDumps(),
            'staging' => $this->pushToStaging(),
        };
    }

    private function pullFromProduction(): int
    {
        $server = config('db-pull.cloud.server');
        $username = config('db-pull.cloud.username');
        $password = config('db-pull.cloud.password');
        $remoteDatabase = config('db-pull.cloud.database');
        $dumpPath = config('db-pull.dump_path');

        if (! $server || ! $password) {
            error('Missing CLOUD_DB_SERVER or CLOUD_DB_PASSWORD environment variables.');

            return Command::FAILURE;
        }

        if (! $this->option('force') && ! confirm('This will overwrite your local database. Are you sure?', false)) {
            info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $ext = $this->driver->dumpExtension();
        $dumpFile = $dumpPath.'/dump-'.now()->format('Y-m-d-His').$ext;

        if (! is_dir($dumpPath)) {
            mkdir($dumpPath, 0755, true);
        }

        $remoteConnection = $this->driver->remoteConnectionString($server, $username, $remoteDatabase);
        $localConnection = $this->buildLocalConnection();

        // Step 1: Dump production
        $dumpResult = spin(
            fn () => $this->runProcess(
                $this->driver->dumpCommand($remoteConnection, $dumpFile),
                $password,
            ),
            'Dumping production database...'
        );

        if (! $dumpResult->successful()) {
            error('Failed to dump production database:');
            $this->line($dumpResult->errorOutput());

            return Command::FAILURE;
        }

        // Step 2: Reset local database
        $resetResult = spin(
            fn () => $this->runProcess(
                $this->driver->resetCommand($localConnection),
                $this->localPassword(),
            ),
            'Resetting local database...'
        );

        if (! $resetResult->successful()) {
            error('Failed to reset local database:');
            $this->line($resetResult->errorOutput());

            return Command::FAILURE;
        }

        // Step 3: Restore to local
        $restoreResult = spin(
            fn () => $this->runProcess(
                $this->driver->restoreCommand($localConnection, $dumpFile),
                $this->localPassword(),
            ),
            'Restoring to local database...'
        );

        if (! $restoreResult->successful()) {
            error('Failed to restore database:');
            $this->line($restoreResult->errorOutput());

            return Command::FAILURE;
        }

        // Step 4: Sanitize
        $sanitized = config('db-pull.sanitize.enabled', true);

        if ($sanitized) {
            if (! $this->sanitize()) {
                return Command::FAILURE;
            }
        } else {
            info('Sanitization skipped (db-pull.sanitize.enabled = false).');
        }

        // Step 5: Offer to run migrations
        if (confirm('Run migrations to apply any new changes?', true)) {
            $this->call('migrate');
        }

        // Cleanup
        $this->handleDumpCleanup($dumpFile);

        info($sanitized
            ? 'Production database pulled and sanitized successfully!'
            : 'Production database pulled successfully (no sanitization applied).');

        return Command::SUCCESS;
    }

    private function restoreFromDump(): int
    {
        $dumps = $this->getDumpFiles();

        if ($dumps->isEmpty()) {
            error('No dump files found in '.config('db-pull.dump_path'));
            warning('Run `php artisan db:pull --keep-dump` to create one.');

            return Command::FAILURE;
        }

        $options = $dumps->mapWithKeys(fn ($dump) => [
            $dump['path'] => "{$dump['name']} ({$dump['size']}, {$dump['date']})",
        ])->toArray();

        $selectedPath = select(
            label: 'Select a dump to restore',
            options: $options,
        );

        $selectedDump = $dumps->firstWhere('path', $selectedPath);

        if (! $this->option('force') && ! confirm(
            "This will overwrite your local database with {$selectedDump['name']}. Continue?",
            false
        )) {
            info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $localConnection = $this->buildLocalConnection();

        // Reset local database
        $resetResult = spin(
            fn () => $this->runProcess(
                $this->driver->resetCommand($localConnection),
                $this->localPassword(),
            ),
            'Resetting local database...'
        );

        if (! $resetResult->successful()) {
            error('Failed to reset local database:');
            $this->line($resetResult->errorOutput());

            return Command::FAILURE;
        }

        // Restore from dump
        $restoreResult = spin(
            fn () => $this->runProcess(
                $this->driver->restoreCommand($localConnection, $selectedDump['path']),
                $this->localPassword(),
            ),
            "Restoring from {$selectedDump['name']}..."
        );

        if (! $restoreResult->successful()) {
            error('Failed to restore database:');
            $this->line($restoreResult->errorOutput());

            return Command::FAILURE;
        }

        if (config('db-pull.sanitize.enabled', true)) {
            if (! $this->sanitize()) {
                return Command::FAILURE;
            }
        } else {
            info('Sanitization skipped (db-pull.sanitize.enabled = false).');
        }

        // Offer to run migrations
        if (confirm('Run migrations to apply any new changes?', true)) {
            $this->call('migrate');
        }

        info("Database restored from {$selectedDump['name']} successfully!");

        return Command::SUCCESS;
    }

    /**
     * Sanitize the freshly restored database.
     *
     * A configured sanitize_command is an Illuminate command, and every
     * command's run() repoints Laravel Prompts' global output at its own.
     * Left alone, the next prompt renders into a buffer nobody reads: the
     * pull looks stuck on "Sanitizing data..." while it silently waits for
     * an answer. So hand the terminal back afterwards.
     */
    private function sanitize(): bool
    {
        try {
            spin(
                fn () => (new Sanitizer)->run(),
                'Sanitizing data...'
            );
        } catch (\Throwable $e) {
            Prompt::setOutput($this->output);
            error('Sanitization failed: '.$e->getMessage());
            warning('The local database still holds unsanitized production data.');

            return false;
        }

        Prompt::setOutput($this->output);

        return true;
    }

    private function pushToStaging(): int
    {
        $stagingServer = config('db-pull.staging.server');
        $stagingUsername = config('db-pull.staging.username');
        $stagingPassword = config('db-pull.staging.password');
        $stagingDatabase = config('db-pull.staging.database');
        $forbiddenDatabases = config('db-pull.forbidden_staging_databases', []);
        $dumpPath = config('db-pull.dump_path');

        if (! $stagingServer || ! $stagingPassword) {
            error('Missing staging database credentials.');

            return Command::FAILURE;
        }

        if (in_array($stagingDatabase, $forbiddenDatabases, true)) {
            error("Cannot push to '{$stagingDatabase}' - this database is protected.");

            return Command::FAILURE;
        }

        if (! $this->option('force') && ! confirm(
            "This will overwrite the staging database ({$stagingDatabase}). Are you sure?",
            false
        )) {
            info('Operation cancelled.');

            return Command::SUCCESS;
        }

        if (! is_dir($dumpPath)) {
            mkdir($dumpPath, 0755, true);
        }

        $ext = $this->driver->dumpExtension();
        $localConnection = $this->buildLocalConnection();
        $stagingConnection = $this->driver->remoteConnectionString($stagingServer, $stagingUsername, $stagingDatabase);
        $sanitizedDumpFile = $dumpPath.'/sanitized-'.now()->format('Y-m-d-His').$ext;

        // Dump the local database
        $dumpResult = spin(
            fn () => $this->runProcess(
                $this->driver->dumpCommand($localConnection, $sanitizedDumpFile),
                $this->localPassword(),
            ),
            'Dumping local database...'
        );

        if (! $dumpResult->successful()) {
            error('Failed to dump local database:');
            $this->line($dumpResult->errorOutput());

            return Command::FAILURE;
        }

        // Reset staging database
        $resetResult = spin(
            fn () => $this->runProcess(
                $this->driver->resetCommand($stagingConnection),
                $stagingPassword,
            ),
            'Resetting staging database...'
        );

        if (! $resetResult->successful()) {
            error('Failed to reset staging database:');
            $this->line($resetResult->errorOutput());
            unlink($sanitizedDumpFile);

            return Command::FAILURE;
        }

        // Restore to staging
        $restoreResult = spin(
            fn () => $this->runProcess(
                $this->driver->restoreCommand($stagingConnection, $sanitizedDumpFile),
                $stagingPassword,
            ),
            'Restoring to staging database...'
        );

        if (! $restoreResult->successful()) {
            error('Failed to restore to staging:');
            $this->line($restoreResult->errorOutput());
            unlink($sanitizedDumpFile);

            return Command::FAILURE;
        }

        // Cleanup
        if ($this->option('keep-dump')) {
            info("Dump file kept at: {$sanitizedDumpFile}");
        } else {
            unlink($sanitizedDumpFile);
            info('Dump file cleaned up.');
        }

        info('Local database pushed to staging successfully!');

        return Command::SUCCESS;
    }

    private function cleanDumps(): int
    {
        $dumps = $this->getDumpFiles();

        if ($dumps->isEmpty()) {
            info('No dump files found.');

            return Command::SUCCESS;
        }

        $totalSize = $dumps->sum(fn ($dump) => filesize($dump['path']));
        info("Found {$dumps->count()} dump file(s) ({$this->formatBytes($totalSize)} total)");

        $options = $dumps->mapWithKeys(fn ($dump) => [
            $dump['path'] => "{$dump['name']} ({$dump['size']}, {$dump['date']})",
        ])->toArray();

        $toDelete = multiselect(
            label: 'Select dumps to delete',
            options: $options,
            hint: 'Use space to select, enter to confirm',
        );

        if (empty($toDelete)) {
            info('No dumps selected.');

            return Command::SUCCESS;
        }

        $count = count($toDelete);
        $selectedDumps = $dumps->whereIn('path', $toDelete);
        $deleteSize = $selectedDumps->sum(fn ($dump) => filesize($dump['path']));

        if (! $this->option('force') && ! confirm(
            "Delete {$count} dump file(s) ({$this->formatBytes($deleteSize)})?",
            false
        )) {
            info('Operation cancelled.');

            return Command::SUCCESS;
        }

        foreach ($toDelete as $path) {
            unlink($path);
        }

        info("Deleted {$count} dump file(s).");

        return Command::SUCCESS;
    }

    private function getDumpFiles(): \Illuminate\Support\Collection
    {
        $dumpPath = config('db-pull.dump_path');

        if (! is_dir($dumpPath)) {
            return collect();
        }

        $ext = $this->driver->dumpExtension();

        return collect(glob($dumpPath.'/*'.$ext))
            ->map(fn ($path) => [
                'path' => $path,
                'name' => basename($path),
                'size' => $this->formatBytes(filesize($path)),
                'date' => date('Y-m-d H:i:s', filemtime($path)),
            ])
            ->sortByDesc('date')
            ->values();
    }

    private function hasStagingConfig(): bool
    {
        return config('db-pull.staging.server') && config('db-pull.staging.password');
    }

    private function buildLocalConnection(): string
    {
        return $this->driver->localConnectionString(
            (string) config('db-pull.local.database'),
            (string) config('db-pull.local.username'),
            config('db-pull.local.port') ? (int) config('db-pull.local.port') : null,
        );
    }

    private function handleDumpCleanup(string $dumpFile): void
    {
        if ($this->option('keep-dump')) {
            info("Dump file kept at: {$dumpFile}");
        } elseif (confirm('Keep the dump file for later use?', false)) {
            info("Dump file kept at: {$dumpFile}");
        } else {
            unlink($dumpFile);
            info('Dump file cleaned up.');
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 1).' '.$units[$pow];
    }
}
