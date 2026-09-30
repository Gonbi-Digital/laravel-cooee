<?php

namespace GonbiDigital\Cooee\Health\Checks;

use GonbiDigital\Cooee\Health\CheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class CacheCheck implements Check
{
    use Timed;

    public function name(): string
    {
        return 'cache';
    }

    public function applies(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        return $this->timed($this->name(), function (): string {
            $store = (string) config('cache.default');
            $key = 'cooee-health:'.Str::random(12);
            $token = Str::random(16);

            /*
             * Write, read back, compare. A Redis that has been failed over to a fresh instance
             * accepts writes and returns null on the read, and a `put()` that merely does not
             * throw would call that healthy - which is how a session store can be silently
             * discarding everything while the check stays green.
             */
            Cache::put($key, $token, 10);
            $read = Cache::get($key);
            Cache::forget($key);

            if ($read !== $token) {
                throw new RuntimeException('Wrote a value to the '.$store.' store and read back something else.');
            }

            return 'read back what was written to '.$store;
        });
    }
}
