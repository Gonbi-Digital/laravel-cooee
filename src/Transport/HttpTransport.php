<?php

namespace GonbiDigital\Cooee\Transport;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * One POST to the monitor's ingest address with the token as a bearer. Cooee answers 202 with an
 * `issueId`, or null for a report it counted but dropped past its daily cap; anything else, or no
 * answer inside the timeout, is a failure that is returned rather than thrown.
 */
class HttpTransport implements Transport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
    ) {}

    public function send(array $payload): Result
    {
        try {
            $response = $this->http
                ->withToken((string) $this->config->get('cooee.token'))
                ->acceptJson()
                ->timeout(max(1, (int) $this->config->get('cooee.timeout', 2)))
                ->post((string) $this->config->get('cooee.url'), $payload);

            if (! $response->successful()) {
                $message = $response->json('message') ?? $response->reason();

                return Result::failed("Cooee answered HTTP {$response->status()}: {$message}", $response->status());
            }

            $issueId = $response->json('issueId');

            return Result::sent($response->status(), is_int($issueId) ? $issueId : null);
        } catch (Throwable $e) {
            return Result::failed($e->getMessage());
        }
    }
}
