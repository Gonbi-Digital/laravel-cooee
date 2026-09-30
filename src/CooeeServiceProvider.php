<?php

namespace GonbiDigital\Cooee;

use GonbiDigital\Cooee\Console\TestCommand;
use GonbiDigital\Cooee\Health\Checks\CacheCheck;
use GonbiDigital\Cooee\Health\Checks\Check;
use GonbiDigital\Cooee\Health\Checks\DatabaseCheck;
use GonbiDigital\Cooee\Health\Checks\QueueCheck;
use GonbiDigital\Cooee\Health\Checks\StorageCheck;
use GonbiDigital\Cooee\Health\HealthReport;
use GonbiDigital\Cooee\Health\Routes as HealthRoutes;
use GonbiDigital\Cooee\Transport\HttpTransport;
use GonbiDigital\Cooee\Transport\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Install, set COOEE_ERRORS_URL and COOEE_ERRORS_TOKEN, done. The provider hooks Laravel's
 * exception handler itself, so nothing has to be added to bootstrap/app.php: every exception the
 * app would have logged is reported, and `report($e)` reports too. The app's own `dontReport`
 * list applies before this package ever sees an exception.
 *
 * Set COOEE_HEALTH_TOKEN as well and the app answers Cooee's health checks on `/health`.
 */
class CooeeServiceProvider extends ServiceProvider
{
    /** The health checks that ship with the package, in the order they appear on the ops page. */
    private const HEALTH_CHECKS = [
        'database' => DatabaseCheck::class,
        'cache' => CacheCheck::class,
        'storage' => StorageCheck::class,
        'queue' => QueueCheck::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cooee.php', 'cooee');

        $this->app->singleton(Transport::class, fn ($app) => new HttpTransport(
            $app->make(HttpFactory::class),
            $app['config'],
        ));

        $this->app->singleton(PayloadBuilder::class);
        $this->app->singleton(Reporter::class);
        $this->app->alias(Reporter::class, 'cooee');

        /*
         * Bound fresh on every resolve, never shared. A report caches its own results so the page
         * and its status code cannot disagree within one request, but a singleton would carry
         * that cache across requests in Octane and serve yesterday's answer forever.
         */
        $this->app->bind(HealthReport::class, fn (): HealthReport => new HealthReport($this->healthChecks()));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cooee');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cooee.php' => $this->app->configPath('cooee.php'),
            ], 'cooee-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/cooee'),
            ], 'cooee-views');

            $this->commands([TestCommand::class]);
        }

        $this->hookExceptionHandler();
        $this->trackRuntime();
        $this->registerHealthRoutes();
    }

    /**
     * Routes cached by `optimize` already include these; registering again would be ignored at
     * best and would throw on a duplicate name at worst.
     */
    private function registerHealthRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        HealthRoutes::register($this->app->make(Router::class), $this->app['config']);
    }

    /** @return list<Check> */
    private function healthChecks(): array
    {
        $checks = [];

        foreach (self::HEALTH_CHECKS as $name => $class) {
            if ($this->app['config']->get('cooee.health.checks.'.$name, true)) {
                $checks[] = $this->app->make($class);
            }
        }

        return $checks;
    }

    /**
     * Laravel's handler calls every `reportable` callback for an exception it is about to log.
     * Registering ours after the handler resolves means it sits alongside the app's own, in
     * whatever order they were added, and never replaces any of them.
     */
    private function hookExceptionHandler(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e): void {
                $this->app->make(Reporter::class)->report($e);
            });
        });
    }

    /**
     * An exception inside a queued job or an artisan command has no request to name, so the job
     * class or the command name goes in its place. Tracked from the framework's own events.
     */
    private function trackRuntime(): void
    {
        $this->callAfterResolving(Dispatcher::class, function (Dispatcher $events): void {
            $reporter = fn (): Reporter => $this->app->make(Reporter::class);

            $events->listen(JobProcessing::class, fn (JobProcessing $event) => $reporter()->inJob($event->job->resolveName()));
            $events->listen(JobProcessed::class, fn () => $reporter()->inJob(null));
            $events->listen(CommandStarting::class, fn (CommandStarting $event) => $reporter()->inCommand($event->command));
            $events->listen(CommandFinished::class, fn () => $reporter()->inCommand(null));
        });
    }
}
