<?php

namespace GonbiDigital\Cooee\Health\Http;

use GonbiDigital\Cooee\Health\HealthReport;
use GonbiDigital\Cooee\Health\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The machine endpoint. JSON only, token only, and - the whole point - **no session**.
 *
 * On `SESSION_DRIVER=database` - a common default - a health route inside the `web`
 * middleware group therefore boots a session out of the very database it is being asked about,
 * so the moment that database goes away `StartSession` throws and the monitor gets a 500 with no
 * explanation instead of `database: SQLSTATE[HY000] [2002] Connection refused`. The endpoint would
 * fail exactly when it is needed, and report nothing about the failure it was built to report.
 *
 * So this route carries no middleware at all and the token is the credential. The ops page is a
 * separate route that can afford a session, because a human reading it is not the thing that has
 * to work during the outage.
 */
class HealthController
{
    public function __invoke(Request $request, HealthReport $report): JsonResponse
    {
        /*
         * 404 rather than 401, and the same 404 for a wrong token as for no token. An unauthorised
         * probe should not be able to learn that there is something here worth probing.
         */
        abort_if(Token::configured() && ! Token::matches($request), 404);

        $payload = $report->toContract();

        return response()->json($payload, $report->status()->httpStatus());
    }
}
