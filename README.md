# Laravel Cooee

Report your Laravel app's exceptions to [Cooee](https://github.com/Gonbi-Digital/gonbi-digital-cooee).
Install it, set two values in `.env`, done: every exception Laravel would have logged is grouped
into an issue on the monitor's **Errors** tab, and the people on the monitor's alerts hear about a
new one, or a fixed one coming back.

Cooee checks a site from the outside. An exception inside the app is invisible to that: the page
still answers 200 and a checkout that throws on every third order looks healthy from the street.
This package is the app telling.

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

## Developing this package

```bash
composer install
composer test      # pint --test, phpstan, pest
```

## Licence

MIT.
