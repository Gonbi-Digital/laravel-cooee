<?php

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
];
