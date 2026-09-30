<?php

namespace GonbiDigital\Cooee;

use GonbiDigital\Cooee\Console\TestCommand;
use GonbiDigital\Cooee\Transport\HttpTransport;
use GonbiDigital\Cooee\Transport\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Install, set COOEE_ERRORS_URL and COOEE_ERRORS_TOKEN, done. The provider hooks Laravel's
 * exception handler itself, so nothing has to be added to bootstrap/app.php: every exception the
 * app would have logged is reported, and `report($e)` reports too. The app's own `dontReport`
 * list applies before this package ever sees an exception.
 */
class CooeeServiceProvider extends ServiceProvider
{
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cooee.php' => $this->app->configPath('cooee.php'),
            ], 'cooee-config');

            $this->commands([TestCommand::class]);
        }

        $this->hookExceptionHandler();
        $this->trackRuntime();
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
