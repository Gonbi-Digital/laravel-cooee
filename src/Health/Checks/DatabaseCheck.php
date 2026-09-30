<?php

namespace GonbiDigital\Cooee\Health\Checks;

use GonbiDigital\Cooee\Health\CheckResult;
use Illuminate\Support\Facades\DB;

/**
 * The check the whole package exists for.
 *
 * An app whose database has gone away still serves cached pages, static assets and its own error
 * handler, so from outside it looks alive. Nothing short of asking it to run a query can tell the
 * difference, which is why this cannot be inferred by an HTTP monitor and has to live in here.
 */
class DatabaseCheck implements Check
{
    use Timed;

    public function name(): string
    {
        return 'database';
    }

    public function applies(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        return $this->timed($this->name(), function (): string {
            $connection = DB::connection();

            // A real round trip. `getPdo()` alone can hand back a connection that was established
            // before the server went away, so it returns happily while every query fails.
            $connection->select('select 1');

            return $connection->getDriverName().' · '.$connection->getDatabaseName();
        });
    }
}
