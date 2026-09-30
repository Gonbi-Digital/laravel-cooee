<?php

use GonbiDigital\Cooee\CooeeTestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends a test exception and reports the issue it opened', function () {
    Http::fake([config('cooee.url') => Http::response(['issueId' => 7], 202)]);

    $this->artisan('cooee:test')
        ->expectsOutputToContain('opened issue #7')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['class'] === CooeeTestException::class
        && $request['message'] === 'Test exception from cooee:test'
        && $request['context']['source'] === 'cooee:test');
});

it('sends even when reporting is switched off, and says so', function () {
    config()->set('cooee.enabled', false);
    Http::fake([config('cooee.url') => Http::response(['issueId' => 7], 202)]);

    $this->artisan('cooee:test', ['--message' => 'Hello from staging'])
        ->expectsOutputToContain('switched off')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['message'] === 'Hello from staging');
});

it('fails plainly when the credentials are missing', function () {
    config()->set('cooee.token', null);
    Http::fake();

    $this->artisan('cooee:test')
        ->expectsOutputToContain('COOEE_ERRORS_TOKEN')
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails when Cooee rejects the report', function () {
    Http::fake([config('cooee.url') => Http::response(['message' => 'The token was not recognised.'], 401)]);

    $this->artisan('cooee:test')
        ->expectsOutputToContain('did not accept')
        ->assertFailed();
});

it('warns when the report was counted but not stored', function () {
    Http::fake([config('cooee.url') => Http::response(['issueId' => null], 202)]);

    $this->artisan('cooee:test')
        ->expectsOutputToContain('daily cap')
        ->assertSuccessful();
});
