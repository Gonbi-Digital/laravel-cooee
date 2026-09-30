<?php

namespace GonbiDigital\Cooee\Health;

use Illuminate\Http\Request;

/**
 * The one place the shared secret is read and compared.
 *
 * Both entrances use it - the machine endpoint and the ops page - because two copies of a
 * credential check are two chances to get one of them subtly wrong, and the wrong one here is
 * silent.
 */
class Token
{
    public static function configured(): bool
    {
        return self::expected() !== '';
    }

    public static function matches(Request $request): bool
    {
        $expected = self::expected();

        if ($expected === '') {
            return false;
        }

        // `hash_equals`, not `===`: comparing a secret with a short-circuiting operator leaks its
        // length and then its content to anyone patient enough to time the responses.
        return hash_equals($expected, self::presented($request));
    }

    private static function expected(): string
    {
        return trim((string) config('cooee.health.token'));
    }

    /**
     * Bearer header first, query string second.
     *
     * The header is what a monitor sends and where a credential belongs. The query parameter is
     * there because a person debugging reaches for a browser, and a URL they can paste is worth
     * more than the small extra exposure of a token in their own history.
     */
    private static function presented(Request $request): string
    {
        return (string) ($request->bearerToken() ?? $request->query('token', ''));
    }
}
