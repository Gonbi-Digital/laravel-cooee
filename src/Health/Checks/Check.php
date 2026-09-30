<?php

namespace GonbiDigital\Cooee\Health\Checks;

use GonbiDigital\Cooee\Health\CheckResult;

interface Check
{
    /** The key this check appears under in the payload - `database`, `cache`, `queue`. */
    public function name(): string;

    /**
     * Whether this check can say anything useful in this app.
     *
     * A check that cannot measure its subject reports nothing rather than guessing: a queue depth
     * is only knowable on the database driver, and a component permanently stuck on "unknown"
     * trains people to skim past the whole list.
     */
    public function applies(): bool;

    public function run(): CheckResult;
}
