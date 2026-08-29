<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Driver
    |--------------------------------------------------------------------------
    |
    | The database driver to use for dump/restore operations.
    | Supported: "mysql", "pgsql"
    |
    */

    'driver' => env('DB_PULL_DRIVER', env('DB_CONNECTION', 'mysql')),

    /*
    |--------------------------------------------------------------------------
    | Cloud (Production) Database
    |--------------------------------------------------------------------------
    */

    'cloud' => [
        'server' => env('CLOUD_DB_SERVER'),
        'username' => env('CLOUD_DB_USERNAME', 'laravel'),
        'password' => env('CLOUD_DB_PASSWORD'),
        'database' => env('CLOUD_DB_DATABASE', 'main'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staging Database
    |--------------------------------------------------------------------------
    */

    'staging' => [
        'server' => env('STAGING_DB_SERVER', env('CLOUD_DB_SERVER')),
        'username' => env('STAGING_DB_USERNAME', env('CLOUD_DB_USERNAME', 'laravel')),
        'password' => env('STAGING_DB_PASSWORD', env('CLOUD_DB_PASSWORD')),
        'database' => env('STAGING_DB_DATABASE', 'staging'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Database
    |--------------------------------------------------------------------------
    */

    'local' => [
        // The Laravel connection used to sanitize the restored data. Null uses
        // your default connection. Set this when the database you pull into is
        // not the app's default — otherwise db-pull would restore production
        // data into one database and sanitize a different one.
        'connection' => env('DB_PULL_CONNECTION'),

        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'database' => env('DB_DATABASE'),

        // Null falls back to the driver default (5432 for pgsql, 3306 for
        // mysql). Set DB_PORT when the local server is not on that port —
        // for example a second Postgres instance running alongside the
        // default one to match a newer remote major version.
        'port' => env('DB_PORT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dump File Path
    |--------------------------------------------------------------------------
    */

    'dump_path' => database_path('dumps'),

    /*
    |--------------------------------------------------------------------------
    | Forbidden Staging Databases
    |--------------------------------------------------------------------------
    |
    | Database names that can never be used as staging push targets.
    |
    */

    'forbidden_staging_databases' => ['main', 'master', 'production', 'prod'],

    /*
    |--------------------------------------------------------------------------
    | Sanitization
    |--------------------------------------------------------------------------
    |
    | Config-driven sanitization runs automatically after each restore.
    |
    | Available strategies:
    |   fake_email  - user{id}@example.com
    |   fake_name   - User {id}
    |   hash_random - bcrypt('password')
    |   fake_phone  - +1555{id padded}
    |   shift_date  - randomly shift by -5 to +5 years
    |   null        - set to NULL
    |
    */

    'sanitize' => [

        // Master switch. Set to false (e.g. DB_PULL_SANITIZE=false in .env) to
        // skip sanitization entirely — useful in trusted local environments
        // where you need real names and emails for testing.
        'enabled' => env('DB_PULL_SANITIZE', true),

        // Global rules: applied to matching column names across ALL tables
        'columns' => [
            'email' => 'fake_email',
            'name' => 'fake_name',
            'password' => 'hash_random',
        ],

        // Per-table overrides (set a column to null to skip the global rule)
        // 'users' => ['name' => null], // don't sanitize name on users table
        'tables' => [],

        // Tables to never sanitize
        'skip_tables' => [
            'cache',
            'cache_locks',
            'failed_jobs',
            'job_batches',
            'jobs',
            'migrations',
            'password_reset_tokens',
            'password_resets',
            'personal_access_tokens',
            'sessions',
            'telescope_entries',
            'telescope_entries_tags',
            'telescope_monitoring',
        ],

        // Rows to preserve (skip sanitization for matching rows)
        // 'table_name' => [
        //     ['column' => 'value'],            // single condition
        //     ['role' => 'admin', 'id' => 1],   // multiple conditions (AND)
        // ],
        'preserve' => [],

        // Records to upsert after sanitization (e.g. a known admin user)
        // 'users' => [
        //     'key' => 'email',
        //     'values' => [
        //         'email' => 'admin@example.com',
        //         'name' => 'Admin User',
        //         'password' => '$2y$12$...',
        //     ],
        // ],
        'seed' => [],

    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitize Command
    |--------------------------------------------------------------------------
    |
    | Optional artisan command to run AFTER config-driven sanitization.
    | Useful for project-specific sanitization logic.
    |
    | Example: 'db:sanitize'
    |
    */

    'sanitize_command' => null,

];
