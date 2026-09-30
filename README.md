# Laravel Cooee

Report your Laravel app's exceptions to [Cooee](https://github.com/Gonbi-Digital/gonbi-digital-cooee).
Install it, set two values in `.env`, done: every exception Laravel would have logged is grouped
into an issue on the monitor's **Errors** tab, and the people on the monitor's alerts hear about a
new one, or a fixed one coming back.

Cooee checks a site from the outside. An exception inside the app is invisible to that: the page
still answers 200 and a checkout that throws on every third order looks healthy from the street.
This package is the app telling.

It also answers the other way: set a health token and the app serves a `/health` endpoint that
Cooee's monitor polls, checking the database, cache, storage and queue from the inside, and a
`/health/ops` page for the people fixing it. See [Health checks](#health-checks).

## Requirements

PHP 8.2+, Laravel 11.23+ (12 and 13 included).

## Install

```bash
composer require gonbi-digital/laravel-cooee
```

In Cooee, edit the monitor, press **Generate** beside _Error reporting token_, copy it, save. Then
open the monitor's **Errors** tab and copy the ingest address. Put both in `.env`:

```dotenv
COOEE_ERRORS_URL=https://cooee.example/api/v1/ingest/monitors/12/errors
COOEE_ERRORS_TOKEN=the-token-you-generated
```

Check the wiring:

```bash
php artisan cooee:test
```

That sends one `CooeeTestException`, prints what Cooee answered, and opens an issue on the Errors
tab that you can mark fixed. Nothing has to be added to `bootstrap/app.php`: the package hooks
the exception handler itself.

## What is reported

Everything Laravel reports. That is the framework's own rule, so validation failures, 404s and
authentication and authorisation exceptions never reach Cooee, and anything you add to
`$exceptions->dontReport([...])` is left out too. `report($e)` reports as well.

Each report carries the exception class, message, file and line (relative to the app root), the
first thirty stack frames, the request URL and method when there was one, the environment, the
release, and a small context: the signed-in user's **id** (only the id, and only if
`include_user` stays on), the route name, the queued job class or the artisan command name, and
the previous exception if there was one.

Reports leave **after the response has been sent**, so a slow or absent Cooee costs the visitor
nothing. In a job or a command they go inline and take at most the timeout, two seconds, and
only when something has already gone wrong. The package never throws.

## Configuration

Publish the config if you want to change anything beyond the two env values:

```bash
php artisan vendor:publish --tag=cooee-config
```

| Key                 | Env                 | Default                             | What it does                                                    |
| ------------------- | ------------------- | ----------------------------------- | --------------------------------------------------------------- |
| `url`, `token`      | `COOEE_ERRORS_*`    | none                                | Where to report and with what. Missing either, nothing is sent  |
| `enabled`           | `COOEE_ENABLED`     | off in `local` and `testing`        | The whole thing on or off                                       |
| `environment`       | `COOEE_ENVIRONMENT` | `app()->environment()`              | Shown on each occurrence                                        |
| `release`           | `COOEE_RELEASE`     | `config('app.version')`             | Shown on each occurrence                                        |
| `ignore_exceptions` |                     | `[]`                                | Classes never sent, subclasses included, on top of `dontReport` |
| `include_user`      |                     | `true`                              | Attach the signed-in user's id                                  |
| `defer`             |                     | `true`                              | Send after the response rather than during it                   |
| `timeout`           |                     | `2`                                 | Seconds the HTTP call may take                                  |
| `trace_frames`      |                     | `30`                                | Frames to send                                                  |

## Adding to a report

Report something you caught yourself, with context of your own:

```php
use GonbiDigital\Cooee\Facades\Cooee;

try {
    $gateway->charge($order);
} catch (GatewayException $e) {
    Cooee::report($e, ['order_id' => $order->id, 'gateway' => 'stripe']);
}
```

Attach context to every report from a service provider or middleware:

```php
Cooee::context(['tenant' => $tenant->slug]);
```

Change or drop a report on its way out. Return the payload to send it, or `null` to drop it:

```php
Cooee::beforeSend(function (array $payload, Throwable $e): ?array {
    if ($e instanceof TooNoisyException) {
        return null;
    }

    unset($payload['context']['user_id']);

    return $payload;
});
```

## Testing your own app

`Cooee::fake()` keeps reports in memory and switches reporting on, so a test can say that a
failure is reported without any env and without a network:

```php
use GonbiDigital\Cooee\Facades\Cooee;

it('reports a failed charge', function () {
    $cooee = Cooee::fake();

    $this->post('/checkout', [...])->assertStatus(500);

    $cooee->assertReported(GatewayException::class, fn (array $payload) => $payload['context']['gateway'] === 'stripe');
});
```

Also `assertNotReported($class)`, `assertNothingReported()` and `assertReportedCount($n)`.

## Health checks

The other direction: Cooee asking the app how it is. Pick a long random string, put it in `.env`,
and paste the same value into the monitor's **Token** field in Cooee:

```dotenv
COOEE_HEALTH_TOKEN=some-long-random-string
```

With a token set, the package registers two routes. Without one it registers nothing, so an app
that only wants error reporting gains no routes.

| Route         | For       | Shape                  | Gate                       | Middleware       |
| ------------- | --------- | ---------------------- | -------------------------- | ---------------- |
| `/health`     | a monitor | the JSON contract      | bearer token, else **404** | **none**         |
| `/health/ops` | a person  | a standalone HTML page | signed in, or the token    | `web` + own gate |

`/health` carries no middleware at all on purpose. With `SESSION_DRIVER=database`, a route inside
the `web` group boots a session out of the database it is being asked about, and the moment that
database goes away the monitor gets a 500 with no explanation instead of `database: SQLSTATE[HY000]
[2002] Connection refused`. The token is the credential, sent as `Authorization: Bearer ...` or,
from a browser, as `?token=...`.

### The contract

```json
{
    "status": "ok",
    "checks": {
        "database": { "status": "ok", "message": "mysql · app_production", "ms": 3 },
        "cache": { "status": "ok", "message": "read back what was written to redis", "ms": 1 },
        "storage": { "status": "ok", "message": "read back what was written to the s3 disk", "ms": 41 },
        "queue": { "status": "degraded", "message": "1204 jobs pending, above the 1000 threshold", "ms": 2 }
    }
}
```

`status` is `ok`, `degraded` or `down`, on the report and on each check, and the overall status is
the **worst** component. `ok` and `degraded` answer 200, `down` answers 503: a queue backlog is
worth knowing about, not worth declaring an outage over.

| Check      | What it actually does                                                                        |
| ---------- | -------------------------------------------------------------------------------------------- |
| `database` | `select 1`. Not `getPdo()`, which hands back a connection opened before the server left.      |
| `cache`    | Writes a token, reads it back, compares. A failed-over Redis accepts writes and returns null. |
| `storage`  | Writes a file, reads it back, deletes it in a `finally`, so a probe cannot litter a bucket.   |
| `queue`    | Pending depth **and** the age of the oldest waiting job, on the `database` driver only.       |

Either queue threshold crossing is `degraded`, never `down`. Switch any check off under
`health.checks` in `config/cooee.php` where it cannot mean anything.

### The ops page

`/health/ops` shows the checks, the drivers the app runs on, whether `optimize` ran, and the
release, commit, branch and deploy time when there are any (`COOEE_RELEASE` or `app.version`, a
`RELEASE` file, and `GIT_SHA` / `GIT_BRANCH` / `DEPLOYED_AT`). It is standalone Blade with inline
CSS, so it renders on the deploy that broke the frontend build. Publish it with
`--tag=cooee-views` to change it.

It does not use Laravel's `auth` middleware, which redirects to a route named `login` that a
Filament app does not have. A stranger gets a **404**. Any signed-in user (on any guard, narrow it
with `health.page.guards`) or the health token gets in, the token being the only way in when the
login system is itself what broke. Set `health.page.allow_authenticated` to `false` for token only.

### Health configuration

| Key                   | Env                      | Default          | What it does                                          |
| --------------------- | ------------------------ | ---------------- | ----------------------------------------------------- |
| `health.token`        | `COOEE_HEALTH_TOKEN`     | none             | The shared secret. `HEARTBEAT_TOKEN` is read too      |
| `health.enabled`      | `COOEE_HEALTH`           | when a token is set | Register the routes. `true` without a token leaves `/health` open: local only |
| `health.path`         | `COOEE_HEALTH_PATH`      | `health`         | Where the JSON endpoint lives                         |
| `health.page.enabled` | `COOEE_HEALTH_PAGE`      | `true`           | Set `false` to register only the endpoint             |
| `health.page.path`    | `COOEE_HEALTH_PAGE_PATH` | `health/ops`     | Where the ops page lives                              |
| `health.queue.*`      |                          | `1000`, `900`    | Pending jobs and seconds waited before `degraded`     |
| `health.storage.disk` |                          | the default disk | The disk the storage check writes to                  |

### Moving over from laravel-heartbeat

This replaces `gonbi-digital/laravel-heartbeat`. Remove that package and require this one. The
routes stay at `/health` and `/health/ops`, and `HEARTBEAT_TOKEN` is still read, so the monitor
keeps working without a change. Then:

- Rename `HEARTBEAT_TOKEN` to `COOEE_HEALTH_TOKEN` when convenient.
- Rename `HEARTBEAT_API_PATH`, `HEARTBEAT_PAGE` and `HEARTBEAT_PAGE_PATH` to `COOEE_HEALTH_PATH`,
  `COOEE_HEALTH_PAGE` and `COOEE_HEALTH_PAGE_PATH` if you set them. `HEARTBEAT_API=false` becomes
  `COOEE_HEALTH=false`.
- Move a published `config/heartbeat.php` into the `health` key of `config/cooee.php`, with `api`
  flattened into `health` and `web` renamed to `page`.
- Route names are now `cooee.health` and `cooee.health.page`, the view is `cooee::health`.
- One behaviour change: with no token set, the routes are not registered at all rather than open.

## Developing this package

```bash
composer install
composer test      # pint --test, phpstan, pest
```

## Licence

MIT.
