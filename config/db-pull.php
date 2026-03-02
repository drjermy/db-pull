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
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'database' => env('DB_DATABASE'),
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
