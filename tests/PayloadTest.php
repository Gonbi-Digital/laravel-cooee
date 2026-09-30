<?php

use GonbiDigital\Cooee\PayloadBuilder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

function thrown(string $message = 'Boom', ?Throwable $previous = null): RuntimeException
{
    try {
        throw new RuntimeException($message, 0, $previous);
    } catch (RuntimeException $e) {
        return $e;
    }
}

it('builds the body the ingest endpoint takes', function () {
    $payload = app(PayloadBuilder::class)->build(thrown('Connection refused'));

    expect($payload)->toHaveKeys(['class', 'message', 'file', 'line', 'trace', 'url', 'method', 'environment', 'release', 'occurredAt', 'context'])
        ->and($payload['class'])->toBe(RuntimeException::class)
        ->and($payload['message'])->toBe('Connection refused')
        ->and($payload['line'])->toBeInt()
        ->and($payload['environment'])->toBe('testing')
        ->and($payload['release'])->toBe('3.1.0')
        ->and($payload['occurredAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('names an exception with no message by its class, because Cooee requires one', function () {
    $payload = app(PayloadBuilder::class)->build(new LogicException);

    expect($payload['message'])->toBe(LogicException::class);
});

it('makes paths relative to the app root with forward slashes', function () {
    $builder = app(PayloadBuilder::class);
    $base = rtrim(base_path(), '/\\');

    expect($builder->relative($base.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Jobs'.DIRECTORY_SEPARATOR.'Sync.php'))->toBe('app/Jobs/Sync.php')
        ->and($builder->relative('C:\\elsewhere\\vendor\\x\\y.php'))->toBe('C:/elsewhere/vendor/x/y.php')
        ->and($builder->build(thrown())['file'])->not->toContain($base);
});

it('formats the trace as "file:line Class->method()", top first, and stops at the configured count', function () {
    config()->set('cooee.trace_frames', 3);

    $trace = app(PayloadBuilder::class)->trace(thrown());

    expect($trace)->toHaveCount(3)
        ->and($trace[0])->toMatch('/:\d+ .*\(\)$/')
        ->and($trace[0])->toContain('thrown()');
});

it('prefers the configured environment and release over the app\'s', function () {
    config()->set(['cooee.environment' => 'staging', 'cooee.release' => 'build-77']);

    $payload = app(PayloadBuilder::class)->build(thrown());

    expect($payload['environment'])->toBe('staging')
        ->and($payload['release'])->toBe('build-77');
});

it('attaches the previous exception, and lays the caller\'s context over the defaults', function () {
    $payload = app(PayloadBuilder::class)->build(
        thrown('Outer', new InvalidArgumentException('Inner')),
        ['order_id' => 88, 'previous' => 'mine'],
    );

    expect($payload['context']['order_id'])->toBe(88)
        ->and($payload['context']['previous'])->toBe('mine');

    $plain = app(PayloadBuilder::class)->build(thrown('Outer', new InvalidArgumentException('Inner')));

    expect($plain['context']['previous'])->toBe(['class' => InvalidArgumentException::class, 'message' => 'Inner']);
});

it('records the request, the route and the signed-in user\'s id, and only the id', function () {
    Http::fake([config('cooee.url') => Http::response(['issueId' => 1], 202)]);
    Route::get('/orders/{id}', fn () => throw new RuntimeException('No such order'))->name('orders.show');

    $user = new User;
    $user->forceFill(['id' => 42, 'name' => 'Ada', 'email' => 'ada@example.test']);

    $this->actingAs($user)->get('/orders/9?x=1')->assertStatus(500);

    Http::assertSent(fn (Request $request) => $request['url'] === 'http://localhost/orders/9?x=1'
        && $request['method'] === 'GET'
        && $request['context']['route'] === 'orders.show'
        && $request['context']['user_id'] === 42
        && ! str_contains($request->body(), 'ada@example.test'));
});

it('leaves the user out when asked to', function () {
    config()->set('cooee.include_user', false);
    Http::fake([config('cooee.url') => Http::response(['issueId' => 1], 202)]);
    Route::get('/boom', fn () => throw new RuntimeException('Boom'));

    $user = new User;
    $user->forceFill(['id' => 42]);

    $this->actingAs($user)->get('/boom')->assertStatus(500);

    Http::assertSent(fn (Request $request) => ! isset($request['context']['user_id']));
});

it('sends no address from the console, where the bound request is a stand-in built from APP_URL', function () {
    // Testbench is a CLI process; only the `testing` env makes it count as the web.
    app()->instance('env', 'production');

    $payload = app(PayloadBuilder::class)->build(thrown());

    expect($payload['url'])->toBeNull()
        ->and($payload['method'])->toBeNull()
        ->and($payload['context'])->not->toHaveKey('route');
});
