# db-pull

Pull your production database down to your local machine and sanitize it in one
command. MySQL and PostgreSQL, Laravel 11/12/13.

```
php artisan db:pull
```

It dumps the remote database, drops and recreates your local one, restores the
dump, replaces every email/name/password it can find with fake data, and offers
to run your pending migrations. Dumps are kept only if you ask.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- The client tools for your database on your `PATH`:
  - MySQL: `mysql`, `mysqldump`
  - PostgreSQL: `psql`, `pg_dump`, `pg_restore`

## Installation

```bash
composer require drjermy/db-pull --dev
php artisan vendor:publish --tag=db-pull-config
```

The service provider is auto-discovered. Publishing the config is optional — the
defaults work — but you'll want it to customise the sanitization rules.

Add your dumps directory to `.gitignore`:

```
/database/dumps
```

## Configuration

Everything is driven by environment variables; see `config/db-pull.php` for the
full set.

```dotenv
CLOUD_DB_SERVER=your-production-host
CLOUD_DB_USERNAME=laravel
CLOUD_DB_PASSWORD=secret
CLOUD_DB_DATABASE=main
```

Your local database uses the standard `DB_*` variables. Set `DB_PORT` if your
local server isn't on the driver default (3306 / 5432) — for example when you
run a second Postgres instance to match a newer remote major version.

Staging is optional and falls back to the cloud credentials:

```dotenv
STAGING_DB_SERVER=your-staging-host
STAGING_DB_PASSWORD=secret
STAGING_DB_DATABASE=staging
```

The driver defaults to `DB_CONNECTION`; override with `DB_PULL_DRIVER=mysql|pgsql`.

Sanitization runs through a Laravel database connection. By default that's your
app's default connection, which is almost always the one you pulled into. If it
isn't, set `DB_PULL_CONNECTION` to the connection whose database matches
`DB_DATABASE` — db-pull checks the two agree and aborts rather than sanitizing
the wrong database.

## Usage

Run it with no options for an interactive menu:

```bash
php artisan db:pull
```

| Option | Effect |
| --- | --- |
| `--force` | Skip the confirmation prompts and pull straight away |
| `--keep-dump` | Keep the dump file after restoring |
| `--from-dump` | Restore from an existing dump instead of pulling fresh |
| `--clean-dumps` | Pick old dump files to delete |
| `--push-to-staging` | Dump your **local** database and restore it over staging |

`--push-to-staging` is the one that writes to a remote server. It refuses any
database named in `forbidden_staging_databases` (`main`, `master`, `production`,
`prod` by default), so a mistyped `STAGING_DB_DATABASE` can't reach production.

## Sanitization

Runs automatically after every restore. Rules are matched by **column name**
across all tables:

```php
'sanitize' => [
    'enabled' => env('DB_PULL_SANITIZE', true),

    'columns' => [
        'email' => 'fake_email',
        'name' => 'fake_name',
        'password' => 'hash_random',
    ],
],
```

| Strategy | Result |
| --- | --- |
| `fake_email` | `user{id}@example.com` |
| `fake_name` | `User {id}` |
| `fake_phone` | `+1555{id, zero-padded}` |
| `hash_random` | `bcrypt('password')` — every user gets the same known password |
| `shift_date` | Shifted by a random -5 to +5 years |
| `null` | Set to `NULL` |

Per-table overrides, with `null` to opt a column out of a global rule:

```php
'tables' => [
    'settings' => ['name' => null],
    'orders' => ['customer_reference' => 'null'],
],
```

Tables to leave alone entirely — the framework tables are already listed:

```php
'skip_tables' => ['migrations', 'jobs', 'sessions', ...],
```

Rows to leave alone. Each entry is a set of conditions ANDed together:

```php
'preserve' => [
    'users' => [
        ['email' => 'me@example.com'],
        ['role' => 'admin', 'team_id' => 1],
    ],
],
```

Records to upsert afterwards, so you always have a known login:

```php
'seed' => [
    'users' => [
        'key' => 'email',
        'values' => [
            'email' => 'admin@example.com',
            'name' => 'Admin User',
            'password' => '$2y$12$...',
        ],
    ],
],
```

For anything the config can't express, point `sanitize_command` at one of your
own artisan commands and it runs last:

```php
'sanitize_command' => 'db:sanitize',
```

Set `DB_PULL_SANITIZE=false` to skip sanitization entirely — only in a trusted
local environment where you need the real data.

## Safety

- `db:pull` refuses to run outside the `local` environment.
- Destructive actions confirm first unless you pass `--force`.
- `--push-to-staging` will not target a forbidden database name.
- The command uses Laravel's `Prohibitable` trait, so you can disable it
  outright from a service provider with `PullDatabase::prohibit()`.
- If sanitization fails, the command reports it and exits non-zero instead of
  quietly leaving unsanitized production data in your local database.

Dump files contain unsanitized production data until they are restored and
sanitized. Keep them out of version control and delete them when you're done —
`php artisan db:pull --clean-dumps`.

## License

MIT. See [LICENSE](LICENSE).
