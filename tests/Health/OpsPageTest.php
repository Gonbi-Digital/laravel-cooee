<?php

use GonbiDigital\Cooee\Health\Http\EnsureOpsPageAccess;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    /*
     * The page is behind `auth` by default. These tests are about what it renders, so the gate
     * comes off here and is asserted on its own at the bottom.
     *
     * Not by setting `cooee.health.page.middleware`: the route is registered when the provider boots,
     * which has already happened by the time a test body runs, so changing that config would
     * assert nothing and pass anyway.
     */
    $this->withoutMiddleware();
});

it('renders standalone, with no layout and no bundle', function () {
    $response = $this->get('/health/ops')->assertOk();

    // The one property that matters: it has to render on the deploy that broke the frontend
    // build, which is the deploy people open it on.
    $response->assertDontSee('@vite', false);
    $response->assertSee('<!DOCTYPE html>', false);
    $response->assertSee('Health checks', false);
});

it('keeps search engines away from it', function () {
    $this->get('/health/ops')->assertSee('noindex', false);
});

it('names every check and how long it took', function () {
    $response = $this->get('/health/ops')->assertOk();

    $response->assertSee('database', false);
    $response->assertSee('cache', false);
    $response->assertSee('storage', false);
});

it('answers json to a client that asks for it', function () {
    $response = $this->getJson('/health/ops')->assertOk();

    // A superset of the contract: the same verdict, plus what a human wants on one screen.
    expect($response->json('status'))->toBe('ok')
        ->and($response->json('checks.database.status'))->toBe('ok')
        ->and($response->json())->toHaveKeys(['app', 'runtime', 'services', 'caches']);
});

it('leaves out the deploy block when this deploy left nothing behind', function () {
    // Plenty of apps have no pipeline writing a RELEASE file, and a card of four "not recorded"
    // rows reads as a fault in the page rather than an absence in the deploy.
    config()->set('app.version', null);
    config()->set('cooee.health.deploy.release_file', 'does-not-exist');

    expect($this->getJson('/health/ops')->json('deploy'))->toBeNull();
    $this->get('/health/ops')->assertDontSee('Deploy', false);
});

it('shows the deploy block when there is something to show', function () {
    config()->set('app.version', '1.6.2');

    expect($this->getJson('/health/ops')->json('deploy.version'))->toBe('1.6.2');
    $this->get('/health/ops')->assertSee('v1.6.2', false);
});

it('is gated, and never by a redirect to a login route', function () {
    /*
     * Laravel's own `auth` middleware cannot be used here: it answers a stranger by redirecting
     * to a route named `login`, and a Filament app has no such route - which turned this page
     * into `Route [login] not defined` in any app that has no such route.
     */
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->getName() === 'cooee.health.page');

    expect($route->gatherMiddleware())
        ->toContain(EnsureOpsPageAccess::class)
        ->not->toContain('auth');
});

it('404s a stranger rather than sending them somewhere', function () {
    config()->set('cooee.health.token', 'sekrit');
    config()->set('cooee.health.page.allow_authenticated', false);

    $response = $this->withMiddleware()->get('/health/ops');

    // Not a 302: there is nowhere sensible to send someone who was not heading here, and an
    // unauthorised visitor should not learn there is anything at this address.
    $response->assertNotFound();
});

it('lets the token in when the login system is part of what broke', function () {
    config()->set('cooee.health.token', 'sekrit');
    config()->set('cooee.health.page.allow_authenticated', false);

    $this->withMiddleware()->get('/health/ops?token=sekrit')->assertOk();
});
