<?php

use GonbiDigital\Cooee\Facades\Cooee;
use GonbiDigital\Cooee\Reporter;
use GonbiDigital\Cooee\Transport\Result;
use GonbiDigital\Cooee\Transport\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class IgnoredException extends RuntimeException {}
class IgnoredChild extends IgnoredException {}

it('reports what the exception handler reports, with nothing added to bootstrap/app.php', function () {
    $fake = Cooee::fake();

    report(new RuntimeException('Something broke'));

    $fake->assertReported(RuntimeException::class, fn (array $payload) => $payload['message'] === 'Something broke');
    $fake->assertReportedCount(1);
});

it('does not report what the app told Laravel not to', function () {
    $fake = Cooee::fake();

    app(ExceptionHandler::class)->ignore(DomainException::class);
    report(new DomainException('Not for the log'));

    $fake->assertNothingReported();
});

it('does nothing when switched off, or when not configured', function () {
    $fake = Cooee::fake();

    config()->set('cooee.enabled', false);
    Cooee::report(new RuntimeException);
    $fake->assertNothingReported();

    config()->set(['cooee.enabled' => true, 'cooee.token' => '']);
    Cooee::report(new RuntimeException);
    $fake->assertNothingReported();

    expect(Cooee::configured())->toBeFalse();
});

it('skips ignored exception classes and their subclasses', function () {
    $fake = Cooee::fake();
    config()->set('cooee.ignore_exceptions', [IgnoredException::class]);

    Cooee::report(new IgnoredException);
    Cooee::report(new IgnoredChild);
    Cooee::report(new RuntimeException);

    $fake->assertNotReported(IgnoredException::class);
    $fake->assertNotReported(IgnoredChild::class);
    $fake->assertReported(RuntimeException::class);
});

it('lets beforeSend change or drop a report', function () {
    $fake = Cooee::fake();

    Cooee::beforeSend(function (array $payload, Throwable $e) {
        if ($e instanceof LogicException) {
            return null;
        }

        $payload['context']['tenant'] = 'acme';

        return $payload;
    });

    Cooee::report(new LogicException('dropped'));
    Cooee::report(new RuntimeException('kept'));

    $fake->assertNotReported(LogicException::class);
    $fake->assertReported(RuntimeException::class, fn (array $payload) => $payload['context']['tenant'] === 'acme');
});

it('attaches global context under per-report context', function () {
    $fake = Cooee::fake();

    Cooee::context(['tenant' => 'acme', 'region' => 'au']);
    Cooee::report(new RuntimeException, ['region' => 'nz', 'order_id' => 5]);

    $fake->assertReported(RuntimeException::class, fn (array $payload) => $payload['context'] === [
        'tenant' => 'acme',
        'region' => 'nz',
        'order_id' => 5,
    ]);
});

it('names the running artisan command in the context', function () {
    $fake = Cooee::fake();

    Event::dispatch(new CommandStarting('rates:refresh', new ArrayInput([]), new NullOutput));
    Cooee::report(new RuntimeException);
    Event::dispatch(new CommandFinished('rates:refresh', new ArrayInput([]), new NullOutput, 1));
    Cooee::report(new LogicException);

    $fake->assertReported(RuntimeException::class, fn (array $payload) => $payload['context']['command'] === 'rates:refresh');
    $fake->assertReported(LogicException::class, fn (array $payload) => ! isset($payload['context']['command']));
});

it('never throws, even when the transport does', function () {
    app()->instance(Transport::class, new class implements Transport
    {
        public function send(array $payload): Result
        {
            throw new RuntimeException('transport exploded');
        }
    });

    expect(fn () => app(Reporter::class)->report(new RuntimeException))->not->toThrow(Throwable::class);
});

it('defers the report past the response for a web request, and sends it on terminate', function () {
    Http::fake([config('cooee.url') => Http::response(['issueId' => 1], 202)]);
    config()->set('cooee.defer', true);

    Route::get('/boom', fn () => throw new RuntimeException('Request failed'));

    $this->get('/boom')->assertStatus(500);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['message'] === 'Request failed' && $request['url'] === 'http://localhost/boom' && $request['method'] === 'GET');
});
