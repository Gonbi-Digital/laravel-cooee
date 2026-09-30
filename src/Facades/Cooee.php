<?php

namespace GonbiDigital\Cooee\Facades;

use GonbiDigital\Cooee\Reporter;
use GonbiDigital\Cooee\Transport\FakeTransport;
use GonbiDigital\Cooee\Transport\Transport;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void report(\Throwable $e, array<string, mixed> $context = [])
 * @method static \GonbiDigital\Cooee\Transport\Result send(\Throwable $e, array<string, mixed> $context = [])
 * @method static bool shouldReport(\Throwable $e)
 * @method static bool enabled()
 * @method static bool configured()
 * @method static Reporter context(array<string, mixed> $context)
 * @method static Reporter beforeSend(\Closure $callback)
 *
 * @see Reporter
 */
class Cooee extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Reporter::class;
    }

    /**
     * Keeps reports in memory for the test to assert on, and switches reporting on with
     * placeholder credentials so a test does not have to set the env to see anything.
     */
    public static function fake(): FakeTransport
    {
        $fake = new FakeTransport;

        static::$app->instance(Transport::class, $fake);
        static::$app['config']->set([
            'cooee.enabled' => true,
            'cooee.defer' => false,
            'cooee.url' => static::$app['config']->get('cooee.url') ?: 'https://cooee.test/api/v1/ingest/monitors/1/errors',
            'cooee.token' => static::$app['config']->get('cooee.token') ?: 'fake',
        ]);

        return $fake;
    }
}
