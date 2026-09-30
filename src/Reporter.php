<?php

namespace GonbiDigital\Cooee;

use Closure;
use GonbiDigital\Cooee\Transport\Result;
use GonbiDigital\Cooee\Transport\Transport;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Decides whether an exception is reported and hands it to the transport. One instance per app,
 * holding the context the app has attached and the job or command it is currently inside.
 *
 * Nothing in here throws. The reporter runs inside the exception handler, and a reporter that
 * becomes the error is worse than one that misses it.
 */
class Reporter
{
    /** @var array<string, mixed> */
    private array $context = [];

    /** @var list<Closure(array<string, mixed>, Throwable): (array<string, mixed>|null)> */
    private array $beforeSend = [];

    private ?string $job = null;

    private ?string $command = null;

    public function __construct(
        private readonly Application $app,
        private readonly PayloadBuilder $payloads,
        private readonly Repository $config,
    ) {}

    /**
     * Reports an exception if it should be, deferred past the response when there is one.
     *
     * @param  array<string, mixed>  $context  attached to this occurrence alone
     */
    public function report(Throwable $e, array $context = []): void
    {
        try {
            if (! $this->shouldReport($e)) {
                return;
            }

            $payload = $this->payload($e, $context);

            if ($payload === null) {
                return;
            }

            // Deferred, the report leaves after the response has gone out and the visitor is not
            // kept waiting on it. In the console there is no response to defer past, and a job
            // or command sending inline costs at most the timeout, and only when something broke.
            if ($this->config->get('cooee.defer', true) && ! Runtime::inConsole($this->app)) {
                defer(fn () => $this->transport()->send($payload), always: true);

                return;
            }

            $this->transport()->send($payload);
        } catch (Throwable) {
            // Never let the reporter become the error.
        }
    }

    /**
     * Reports now, whatever `enabled` says, and returns what Cooee answered. For `cooee:test`
     * and for anything else that wants to know the report arrived.
     *
     * @param  array<string, mixed>  $context
     */
    public function send(Throwable $e, array $context = []): Result
    {
        if (! $this->configured()) {
            return Result::failed('COOEE_ERRORS_URL and COOEE_ERRORS_TOKEN are not both set.');
        }

        $payload = $this->payload($e, $context);

        if ($payload === null) {
            return Result::failed('A beforeSend callback dropped the report.');
        }

        return $this->transport()->send($payload);
    }

    public function shouldReport(Throwable $e): bool
    {
        if (! $this->enabled() || ! $this->configured()) {
            return false;
        }

        foreach ((array) $this->config->get('cooee.ignore_exceptions', []) as $ignored) {
            if (is_string($ignored) && $e instanceof $ignored) {
                return false;
            }
        }

        return true;
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('cooee.enabled', true);
    }

    public function configured(): bool
    {
        return (string) $this->config->get('cooee.url', '') !== ''
            && (string) $this->config->get('cooee.token', '') !== '';
    }

    /**
     * Attaches context to every report from here on: a tenant, a request id, a feature flag.
     * Merged under whatever a single report attaches.
     *
     * @param  array<string, mixed>  $context
     */
    public function context(array $context): static
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    /**
     * A last look at the payload before it leaves. Return the payload, changed or not, to send
     * it; return null to drop it. Several callbacks run in the order they were added.
     *
     * @param  Closure(array<string, mixed>, Throwable): (array<string, mixed>|null)  $callback
     */
    public function beforeSend(Closure $callback): static
    {
        $this->beforeSend[] = $callback;

        return $this;
    }

    public function inJob(?string $job): void
    {
        $this->job = $job;
    }

    public function inCommand(?string $command): void
    {
        $this->command = $command;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function payload(Throwable $e, array $context): ?array
    {
        $runtime = array_filter(['job' => $this->job, 'command' => $this->command]);

        $payload = $this->payloads->build($e, [...$this->context, ...$runtime, ...$context]);

        foreach ($this->beforeSend as $callback) {
            $payload = $callback($payload, $e);

            if ($payload === null) {
                return null;
            }
        }

        return $payload;
    }

    /** Resolved per send rather than held, so `Cooee::fake()` can swap it under a running app. */
    private function transport(): Transport
    {
        return $this->app->make(Transport::class);
    }
}
