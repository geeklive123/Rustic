<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // RefreshDatabase must never reach a persistent or development database.
        if ($app->environment() !== 'testing' || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Las pruebas requieren SQLite en memoria y APP_ENV=testing.');
        }

        // Tests cannot select a persistent database using an explicit connection name.
        $sqlite = $app['config']->get('database.connections.sqlite');
        $app['config']->set('database.connections', ['sqlite' => $sqlite]);

        return $app;
    }
}
