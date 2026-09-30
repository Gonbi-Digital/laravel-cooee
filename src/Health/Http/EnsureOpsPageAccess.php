<?php

namespace GonbiDigital\Cooee\Health\Http;

use Closure;
use GonbiDigital\Cooee\Health\Token;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may read the ops page.
 *
 * Laravel's own `auth` middleware cannot be used here. It answers an unauthenticated visitor by
 * redirecting to a route named `login`, and a Filament app has no such route - so the page blew
 * up with `Route [login] not defined`, turning a diagnostic screen into a 500 that had nothing to
 * do with the thing being diagnosed.
 *
 * So access is decided here and the answer to a stranger is **404**, never a redirect. There is
 * nowhere sensible to send someone who is not signed in: the page is not a destination they were
 * heading to, and an unauthorised visitor should not learn there is something here at all.
 *
 * Two credentials are accepted, because the page has two kinds of reader:
 *
 * - a **signed-in user**, which is the ordinary case and needs no secret shared around;
 * - the **health token**, which is the only way in when the login system is itself part of what
 *   has broken - and that is the afternoon somebody needs this page most.
 */
class EnsureOpsPageAccess
{
    public function __invoke(Request $request, Closure $next): Response
    {
        return $this->handle($request, $next);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (Token::matches($request)) {
            return $next($request);
        }

        if (config('cooee.health.page.allow_authenticated', true) && $this->signedIn()) {
            return $next($request);
        }

        abort(404);
    }

    /**
     * Any of the app's own guards will do. Naming one would mean guessing: a Filament panel, a
     * Livewire front end and a plain `web` guard all authenticate somewhere different.
     */
    private function signedIn(): bool
    {
        $guards = (array) config('cooee.health.page.guards', []);

        if ($guards === []) {
            $guards = array_keys((array) config('auth.guards', []));
        }

        foreach ($guards as $guard) {
            // A guard can throw when it is configured for a driver this request cannot satisfy -
            // a session guard on a stateless request, say. That is a "no", not a 500.
            try {
                if (Auth::guard($guard)->check()) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }
}
