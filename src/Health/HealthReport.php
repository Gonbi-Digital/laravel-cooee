<?php

namespace GonbiDigital\Cooee\Health;

use GonbiDigital\Cooee\Health\Checks\Check;
use Illuminate\Support\Carbon;

/**
 * One report, read two ways.
 *
 * `toContract()` is the machine shape a monitor consumes and is deliberately small - a status and
 * a list of components, nothing else. `toArray()` adds everything a human standing in front of a
 * bad deploy wants: which release this is, which drivers are configured, whether `optimize` ran.
 *
 * They are the same run. Building them separately would let the page and the monitor disagree
 * about whether the app is healthy, which is the one thing a diagnostic must never do.
 */
class HealthReport
{
    /** @var array<int, CheckResult>|null */
    private ?array $results = null;

    /** @param  array<int, Check>  $checks */
    public function __construct(private readonly array $checks) {}

    public function status(): Status
    {
        return Status::worst(array_map(fn (CheckResult $r): Status => $r->status, $this->results()));
    }

    /** @return array<int, CheckResult> */
    public function results(): array
    {
        return $this->results ??= array_values(array_map(
            fn (Check $check): CheckResult => $check->run(),
            array_filter($this->checks, fn (Check $check): bool => $check->applies()),
        ));
    }

    /**
     * The shape a monitor reads. Keyed by component name, because a consumer wants to ask "how is
     * the database" without scanning a list, and because a stable key survives reordering.
     *
     * @return array{status: string, checks: array<string, array{status: string, message: string|null, ms: int|null}>}
     */
    public function toContract(): array
    {
        $checks = [];

        foreach ($this->results() as $result) {
            $checks[$result->name] = $result->toArray();
        }

        return [
            'status' => $this->status()->value,
            'checks' => $checks,
        ];
    }

    /**
     * Everything, for the ops page - and for a monitor that wants the detail, since it is a
     * superset of the contract and the extra keys are ignored by anything reading only `status`
     * and `checks`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->toContract(),
            'app' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'maintenance' => app()->isDownForMaintenance(),
                'url' => config('app.url'),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'timezone' => config('app.timezone'),
                'locale' => app()->getLocale(),
            ],
            // Named drivers, not probes. The checks above say whether they answer; this says which
            // one was asked, which is how "it works locally" gets settled in one look.
            'services' => [
                'database' => (string) config('database.default'),
                'cache' => (string) config('cache.default'),
                'queue' => (string) config('queue.default'),
                'session' => (string) config('session.driver'),
                'mail' => (string) config('mail.default'),
                'filesystem' => (string) config('filesystems.default'),
            ],
            // Whether `optimize` actually ran. A deploy that half finished shows up here first.
            'caches' => [
                'config' => app()->configurationIsCached(),
                'routes' => app()->routesAreCached(),
                'events' => app()->eventsAreCached(),
            ],
            'deploy' => $this->deploy(),
            'checked_at' => Carbon::now()->utc()->toIso8601String(),
            'render_ms' => defined('LARAVEL_START')
                ? (int) round((microtime(true) - LARAVEL_START) * 1000)
                : null,
        ];
    }

    /**
     * Whatever this deploy left behind, or null when it left nothing.
     *
     * Plenty of apps are deployed by something that writes no `RELEASE` file and exports no SHA,
     * and a block of four "unknown"s reads as a fault in the page rather than an absence in the
     * deploy. So the key is omitted entirely unless something real is there: the page and the
     * payload simply do not mention a release when there is no notion of one.
     *
     * The version is the same one the error reports carry, so an issue in Cooee and this page
     * name a deploy the same way.
     *
     * @return array<string, string>|null
     */
    private function deploy(): ?array
    {
        $file = base_path((string) config('cooee.health.deploy.release_file', 'RELEASE'));

        $deploy = array_filter([
            'version' => config('cooee.release') ?: config('app.version'),
            'release' => is_readable($file) ? (trim((string) file_get_contents($file)) ?: null) : null,
            'sha' => config('app.deploy.sha') ?? config('cooee.health.deploy.sha'),
            'branch' => config('app.deploy.branch') ?? config('cooee.health.deploy.branch'),
            'at' => config('app.deploy.at') ?? config('cooee.health.deploy.at'),
        ], fn ($value): bool => is_scalar($value) && (string) $value !== '');

        return $deploy === [] ? null : array_map(strval(...), $deploy);
    }
}
