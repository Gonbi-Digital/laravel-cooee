<?php

use GonbiDigital\Cooee\Health\Http\EnsureOpsPageAccess;

return [
    /*
     * Where to report, and with what. Both come from the monitor's Errors tab in Cooee: the
     * ingest address has the monitor's id in it, and the token is generated once and never
     * shown again. With either missing the package does nothing, quietly.
     */
    'url' => env('COOEE_ERRORS_URL'),
    'token' => env('COOEE_ERRORS_TOKEN'),

    /*
     * A switch for the whole thing. Off in local and testing by default, because an exception
     * on a developer's machine is not an outage, and a test that throws on purpose is not a bug.
     */
    'enabled' => env('COOEE_ENABLED', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    /*
     * Shown on every occurrence in Cooee. Null means "ask the app": the environment is
     * `app()->environment()`, the release is `config('app.version')` when there is one.
     */
    'environment' => env('COOEE_ENVIRONMENT'),
    'release' => env('COOEE_RELEASE'),

    /*
     * Exceptions never sent, on top of the app's own `dontReport` list, which already applies:
     * Laravel only hands the package what it would itself have logged. Instances of a listed
     * class, or of a subclass, are dropped.
     */
    'ignore_exceptions' => [
        // App\Exceptions\Ignorable::class,
    ],

    /*
     * Whether the signed-in user's id goes with the report. Only the id, never the name or the
     * email. Off, and an error is anonymous.
     */
    'include_user' => true,

    /*
     * How the report leaves the app. Deferred, it goes after the response has been sent (or the
     * job or command has finished), so a slow or absent Cooee costs the visitor nothing. Sync
     * sends before continuing, which is what a test or a short-lived script wants.
     */
    'defer' => true,

    /* Seconds the HTTP call may take. Cooee answers in milliseconds; this is for when it does not. */
    'timeout' => 2,

    /* Stack frames to send, top of the stack first. Cooee stores thirty. */
    'trace_frames' => 30,

    /*
     * The other direction: Cooee asking the app how it is. A JSON endpoint the monitor polls, and
     * an ops page for the humans. Everything above is about errors; everything in here is about
     * health, and the two never share a credential.
     */
    'health' => [
        /*
         * The shared secret for the JSON endpoint. Choose it here and paste the same value into
         * the monitor's Token field in Cooee. `HEARTBEAT_TOKEN` is still read, for apps that
         * moved over from gonbi-digital/laravel-heartbeat.
         */
        'token' => env('COOEE_HEALTH_TOKEN', env('HEARTBEAT_TOKEN')),

        /*
         * Whether the routes are registered at all. Null means "when a token is set", so an app
         * that only wants error reporting gains no routes. Set it true without a token and the
         * endpoint is open to anyone who finds it: acceptable locally, never in production,
         * because the payload names every driver this app runs on.
         */
        'enabled' => env('COOEE_HEALTH'),

        /*
         * The machine endpoint: JSON, token-gated, and registered with **no middleware at all**.
         *
         * That is not an oversight. Plenty of apps keep sessions in the database, so a route
         * inside the `web` group boots a session out of the database it is being asked about,
         * and answers 500 instead of "the database is gone" at the one moment the answer matters.
         */
        'path' => env('COOEE_HEALTH_PATH', 'health'),
        'middleware' => [],

        /*
         * The ops page for humans. Behind the session, because whoever reads it is signed in
         * anyway and a page listing drivers and release SHAs is not for the public. If the
         * database is down this route will fail with it; that is what the endpoint above is for.
         *
         * It lives under `health/` so that an app which already has an ops page of its own keeps
         * it, untouched. Set `COOEE_HEALTH_PAGE=false` there and only the endpoint is registered.
         */
        'page' => [
            'enabled' => env('COOEE_HEALTH_PAGE', true),
            'path' => env('COOEE_HEALTH_PAGE_PATH', 'health/ops'),
            'middleware' => ['web', EnsureOpsPageAccess::class],

            /*
             * Whether being signed in is enough to read the page. The health token always is,
             * which is the only way in when the login system is itself part of what has broken.
             */
            'allow_authenticated' => true,

            /* Which guards count as signed in. Empty means every guard the app has configured. */
            'guards' => [],
        ],

        /* Each may be switched off where it cannot mean anything: an app with no queue, say. */
        'checks' => [
            'database' => true,
            'cache' => true,
            'storage' => true,
            'queue' => true,
        ],

        'queue' => [
            // Depth alone misses a worker that died on a quiet afternoon, so age is watched too.
            'pending_threshold' => 1000,
            'stale_after_seconds' => 900,
        ],

        'storage' => [
            // null follows `filesystems.default`. Name a disk to probe a specific one instead.
            'disk' => null,
        ],

        /*
         * What the ops page shows about the running deploy. Read here rather than where they are
         * used, because `env()` returns null everywhere else once the config is cached.
         */
        'deploy' => [
            'release_file' => 'RELEASE',
            'sha' => env('GIT_SHA'),
            'branch' => env('GIT_BRANCH'),
            'at' => env('DEPLOYED_AT'),
        ],
    ],
];
