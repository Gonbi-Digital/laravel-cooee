<?php

namespace GonbiDigital\Cooee;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns an exception into the body Cooee's ingest endpoint takes. Paths are made relative to the
 * app root with forward slashes whatever the host, because Cooee tells app frames from vendor
 * frames by looking for `vendor/`, and `vendor\` on a Windows host would put every frame in app.
 */
class PayloadBuilder
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly Router $router,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(Throwable $e, array $context = []): array
    {
        $request = $this->request();

        return [
            'class' => $e::class,
            // Cooee requires a message; an exception thrown with none is named by its class.
            'message' => $e->getMessage() !== '' ? $e->getMessage() : $e::class,
            'file' => $this->relative($e->getFile()),
            'line' => $e->getLine(),
            'trace' => $this->trace($e),
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
            'environment' => $this->config->get('cooee.environment') ?: $this->app->environment(),
            'release' => $this->config->get('cooee.release') ?: $this->config->get('app.version'),
            'occurredAt' => Carbon::now()->toIso8601String(),
            'context' => $this->context($e, $request, $context),
        ];
    }

    /**
     * "file:line Class->method()" per frame, top of the stack first, the way Cooee's own docs
     * word it. A frame with no file is internal to PHP and says so.
     *
     * @return list<string>
     */
    public function trace(Throwable $e): array
    {
        $limit = max(1, (int) $this->config->get('cooee.trace_frames', 30));
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, $limit) as $frame) {
            $frames[] = sprintf(
                '%s:%s %s%s%s()',
                isset($frame['file']) ? $this->relative($frame['file']) : '[internal]',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
            );
        }

        return $frames;
    }

    public function relative(string $path): string
    {
        $base = rtrim($this->app->basePath(), '/\\');

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = ltrim(substr($path, strlen($base)), '/\\');
        }

        return str_replace('\\', '/', $path);
    }

    /**
     * What the app knows that the exception does not: who was signed in (their id only), which
     * route, and the exception underneath this one. The caller's own context is laid over it.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function context(Throwable $e, ?Request $request, array $context): array
    {
        $defaults = [];

        if ($request !== null) {
            if ($this->config->get('cooee.include_user', true) && ($user = $request->user()) !== null) {
                $defaults['user_id'] = $user->getAuthIdentifier();
            }

            // The router knows which route it dispatched; a request that failed before routing
            // has none, and the router says so with a null rather than a fatal.
            if (($route = $this->router->currentRouteName()) !== null) {
                $defaults['route'] = $route;
            }
        }

        if (($previous = $e->getPrevious()) !== null) {
            $defaults['previous'] = [
                'class' => $previous::class,
                'message' => mb_strimwidth($previous->getMessage(), 0, 500, '…'),
            ];
        }

        return [...$defaults, ...$context];
    }

    /**
     * The request being handled, or null in the console, where Laravel binds a stand-in request
     * built from APP_URL that would otherwise put "GET https://app.example" on every job failure.
     */
    private function request(): ?Request
    {
        if (Runtime::inConsole($this->app) || ! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return $request instanceof Request ? $request : null;
    }
}
