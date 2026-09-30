<?php

namespace GonbiDigital\Cooee\Tests;

use GonbiDigital\Cooee\CooeeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CooeeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Configured and on, synchronous, so a report is a request the test can assert on.
        $app['config']->set([
            'cooee.url' => 'https://cooee.test/api/v1/ingest/monitors/12/errors',
            'cooee.token' => 'secret-token',
            'cooee.enabled' => true,
            'cooee.defer' => false,
            'app.version' => '3.1.0',
        ]);

        // The health routes, on for every test. Registering them hangs on a token by default,
        // which the health tests switch on and off per test.
        $app['config']->set([
            'cooee.health.enabled' => true,
            // The ops page runs the `web` group, which encrypts cookies and so needs a key.
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
            'filesystems.default' => 'local',
            'queue.default' => 'sync',
        ]);
    }
}
