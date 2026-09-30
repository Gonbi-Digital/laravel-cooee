<?php

namespace GonbiDigital\Cooee\Health;

use Throwable;

/**
 * What one probe found.
 *
 * A value object rather than an array so a check cannot return half a result, and so the two
 * consumers - the JSON contract and the ops page - read the same shape.
 */
class CheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly Status $status,
        public readonly ?string $message = null,
        public readonly ?int $ms = null,
    ) {}

    public static function ok(string $name, ?string $message = null, ?int $ms = null): self
    {
        return new self($name, Status::Ok, $message, $ms);
    }

    public static function degraded(string $name, string $message, ?int $ms = null): self
    {
        return new self($name, Status::Degraded, $message, $ms);
    }

    public static function down(string $name, string $message, ?int $ms = null): self
    {
        return new self($name, Status::Down, $message, $ms);
    }

    /**
     * The failure as the thing that actually went wrong, truncated to fit a column and a card.
     *
     * The exception message is the whole point: "SQLSTATE[HY000] [2002] Connection refused" is the
     * difference between "something is wrong" and knowing which service to restart. Swallowing it
     * in favour of "database check failed" is the most common way a health endpoint becomes
     * useless at the one moment it matters.
     */
    public static function fromException(string $name, Throwable $exception, ?int $ms = null): self
    {
        $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');

        return new self(
            $name,
            Status::Down,
            $message === '' ? $exception::class : mb_substr($message, 0, 200),
            $ms,
        );
    }

    /** @return array{status: string, message: string|null, ms: int|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'ms' => $this->ms,
        ];
    }
}
