<?php

namespace GonbiDigital\Cooee\Transport;

/** What one delivery came to: accepted with an issue id, or not, and why. */
final class Result
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $status,
        public readonly ?int $issueId,
        public readonly ?string $error,
    ) {}

    public static function sent(int $status, ?int $issueId): self
    {
        return new self(true, $status, $issueId, null);
    }

    public static function failed(string $error, ?int $status = null): self
    {
        return new self(false, $status, null, $error);
    }
}
