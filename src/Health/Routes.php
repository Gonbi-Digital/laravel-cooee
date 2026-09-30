<?php

namespace GonbiDigital\Cooee\Health;

use GonbiDigital\Cooee\Health\Http\EnsureOpsPageAccess;
use GonbiDigital\Cooee\Health\Http\HealthController;
use GonbiDigital\Cooee\Health\Http\OpsPageController;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;

/**
 * The two health routes, deliberately separate: `/health` for the monitor, with no middleware at
 * all, and `/health/ops` for a person, behind the session.
 */
final class Routes
{
    /**
     * An app that installed the package for error reporting alone gains no routes: unless
     * `cooee.health.enabled` says otherwise, they exist only once a health token has been set.
     */
    public static function enabled(Repository $config): bool
    {
        $enabled = $config->get('cooee.health.enabled');

        if ($enabled === null || $enabled === '') {
            return trim((string) $config->get('cooee.health.token')) !== '';
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOL);
    }

    public static function register(Router $router, Repository $config): void
    {
        if (! self::enabled($config)) {
            return;
        }

        $router->get((string) $config->get('cooee.health.path', 'health'), HealthController::class)
            ->middleware((array) $config->get('cooee.health.middleware', []))
            ->name('cooee.health');

        if (filter_var($config->get('cooee.health.page.enabled', true), FILTER_VALIDATE_BOOL)) {
            $router->get((string) $config->get('cooee.health.page.path', 'health/ops'), OpsPageController::class)
                ->middleware((array) $config->get('cooee.health.page.middleware', ['web', EnsureOpsPageAccess::class]))
                ->name('cooee.health.page');
        }
    }
}
