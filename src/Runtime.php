<?php

namespace GonbiDigital\Cooee;

use Illuminate\Contracts\Foundation\Application;

/**
 * One answer to "is this a console run": an artisan command or a queue worker, where the bound
 * request is a stand-in built from APP_URL and there is no response to defer past. PHPUnit is a
 * CLI process too, but the requests a test sends through the HTTP kernel are real, so a test run
 * counts as the web.
 */
final class Runtime
{
    public static function inConsole(Application $app): bool
    {
        return $app->runningInConsole() && ! $app->runningUnitTests();
    }
}
