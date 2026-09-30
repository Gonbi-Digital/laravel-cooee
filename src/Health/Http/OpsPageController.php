<?php

namespace GonbiDigital\Cooee\Health\Http;

use GonbiDigital\Cooee\Health\HealthReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a human opens when a deploy went wrong.
 *
 * Standalone Blade with inline CSS - no Inertia, no Vite bundle, no layout inheritance. A
 * diagnostic screen that needs the frontend build to render is no use on the deploy that broke
 * the frontend build, and that is the deploy people open it on.
 *
 * It answers JSON to `Accept: application/json` as well, carrying the full report rather than the
 * bare contract. A monitor should point at `/health` instead, which has no session middleware in
 * front of it; this is for the person who is already signed in and wants everything on one screen.
 */
class OpsPageController
{
    public function __invoke(Request $request, HealthReport $report): Response
    {
        $payload = $report->toArray();
        $status = $report->status();

        if ($request->expectsJson()) {
            return response()->json($payload, $status->httpStatus());
        }

        return response()->view('cooee::health', [
            'report' => $report,
            'payload' => $payload,
            'status' => $status,
        ], $status->httpStatus());
    }
}
