<?php

namespace GonbiDigital\Cooee\Health\Checks;

use GonbiDigital\Cooee\Health\CheckResult;
use Throwable;

/**
 * Runs a probe, times it, and turns a thrown exception into a `down` rather than a 500.
 *
 * A health endpoint that crashes reports nothing. Every check is wrapped so that one broken
 * service is a red line on a page of otherwise green ones, instead of a stack trace where the
 * report should be.
 */
trait Timed
{
    /** @param  callable(): ?string  $probe  Returns the detail line, or null for none. */
    protected function timed(string $name, callable $probe): CheckResult
    {
        $startedAt = microtime(true);

        try {
            $message = $probe();
        } catch (Throwable $exception) {
            return CheckResult::fromException($name, $exception, $this->elapsed($startedAt));
        }

        return CheckResult::ok($name, $message, $this->elapsed($startedAt));
    }

    protected function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
