<?php

use GonbiDigital\Cooee\Facades\Cooee;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts the report to the ingest address with the token as a bearer', function () {
    Http::fake([config('cooee.url') => Http::response(['issueId' => 41], 202)]);

    $result = Cooee::send(new RuntimeException('Refused'));

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(202)
        ->and($result->issueId)->toBe(41);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://cooee.test/api/v1/ingest/monitors/12/errors'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->hasHeader('Accept', 'application/json')
        && $request['class'] === RuntimeException::class
        && $request['message'] === 'Refused'
        && is_array($request['trace']));
});

it('reports a dropped event as sent with no issue id', function () {
    Http::fake([config('cooee.url') => Http::response(['issueId' => null], 202)]);

    $result = Cooee::send(new RuntimeException);

    expect($result->ok)->toBeTrue()->and($result->issueId)->toBeNull();
});

it('returns a failure for a rejected token rather than throwing', function () {
    Http::fake([config('cooee.url') => Http::response(['code' => 'error.ingest.unauthorised', 'message' => 'The token was not recognised.'], 401)]);

    $result = Cooee::send(new RuntimeException);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(401)
        ->and($result->error)->toContain('HTTP 401')->toContain('not recognised');
});

it('returns a failure when Cooee cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    $result = Cooee::send(new RuntimeException);

    expect($result->ok)->toBeFalse()->and($result->error)->toContain('timed out');
});
