<?php

namespace DrJermy\DbPull\Tests;

use DrJermy\DbPull\DbPullServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DbPullServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // The sanitizer refuses to run unless the connection it resolves is
        // the database the pull restored into, so the two must agree here.
        $app['config']->set('db-pull.local.database', ':memory:');

        // Start from nothing rather than the shipped defaults, so each test
        // states the whole rule set it depends on.
        $app['config']->set('db-pull.sanitize.columns', []);
        $app['config']->set('db-pull.sanitize.skip_tables', []);
        $app['config']->set('db-pull.sanitize.tables', []);
        $app['config']->set('db-pull.sanitize.preserve', []);
        $app['config']->set('db-pull.sanitize.seed', []);
    }
}
