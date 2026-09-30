<?php

namespace GonbiDigital\Cooee\Console;

use GonbiDigital\Cooee\CooeeTestException;
use GonbiDigital\Cooee\Reporter;
use Illuminate\Console\Command;

/**
 * Sends one deliberate exception and says what Cooee answered, so "is it wired up" is a command
 * rather than a wait for something to break. The report is real: it opens an issue on the
 * monitor's Errors tab, which is the point, and can be marked fixed there.
 */
class TestCommand extends Command
{
    protected $signature = 'cooee:test {--message= : The message to send instead of the default}';

    protected $description = 'Report a test exception to Cooee and show the answer';

    public function handle(Reporter $reporter): int
    {
        if (! $reporter->configured()) {
            $this->components->error('Set COOEE_ERRORS_URL and COOEE_ERRORS_TOKEN first. Both are on the monitor\'s Errors tab in Cooee.');

            return self::FAILURE;
        }

        $message = (string) ($this->option('message') ?: 'Test exception from cooee:test');

        $this->components->info('Sending a test exception to '.config('cooee.url').' ...');

        $result = $reporter->send(new CooeeTestException($message), ['source' => 'cooee:test']);

        if (! $result->ok) {
            $this->components->error('Cooee did not accept the report: '.$result->error);

            return self::FAILURE;
        }

        if ($result->issueId === null) {
            $this->components->warn("Cooee answered HTTP {$result->status} but stored nothing: the monitor is past its daily cap of new issues.");

            return self::SUCCESS;
        }

        $this->components->info("Reported. Cooee answered HTTP {$result->status} and opened issue #{$result->issueId}. It is on the monitor's Errors tab; mark it fixed there.");

        if (! $reporter->enabled()) {
            $this->components->warn('Reporting is switched off in this environment (cooee.enabled is false), so real exceptions will not be sent from here.');
        }

        return self::SUCCESS;
    }
}
