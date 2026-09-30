<?php

namespace GonbiDigital\Cooee\Transport;

use Closure;
use PHPUnit\Framework\Assert;

/**
 * Swapped in by `Cooee::fake()`: keeps every payload instead of sending it, and asserts on them.
 * For an app's own tests, so "this failure is reported" can be a line in a test rather than a
 * hope.
 */
class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $payload): Result
    {
        $this->sent[] = $payload;

        return Result::sent(202, count($this->sent));
    }

    /**
     * @param  class-string  $class
     * @param  (Closure(array<string, mixed>): bool)|null  $callback  a further check on the payload
     */
    public function assertReported(string $class, ?Closure $callback = null): void
    {
        $matching = array_filter(
            $this->sent,
            fn (array $payload) => $payload['class'] === $class && ($callback === null || $callback($payload)),
        );

        Assert::assertNotEmpty($matching, "No [{$class}] was reported to Cooee.");
    }

    /** @param  class-string  $class */
    public function assertNotReported(string $class): void
    {
        $matching = array_filter($this->sent, fn (array $payload) => $payload['class'] === $class);

        Assert::assertEmpty($matching, "A [{$class}] was reported to Cooee.");
    }

    public function assertNothingReported(): void
    {
        Assert::assertSame([], $this->sent, count($this->sent).' report(s) were sent to Cooee.');
    }

    public function assertReportedCount(int $count): void
    {
        Assert::assertCount($count, $this->sent, 'The number of reports sent to Cooee did not match.');
    }
}
