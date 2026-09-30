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
    }
}
