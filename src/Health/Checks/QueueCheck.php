<?php

namespace GonbiDigital\Cooee\Health\Checks;

use GonbiDigital\Cooee\Health\CheckResult;
use GonbiDigital\Cooee\Health\Status;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether anything is still draining the queue.
 *
 * Two numbers, because one of them lies. A backlog count alone misses the most common failure -
 * a worker that died on a quiet afternoon, leaving a queue that is nearly empty and entirely
 * stuck. So the age of the oldest waiting job is checked as well: a handful of jobs that have
 * been sitting for an hour is a dead worker, while a thousand jobs thirty seconds old is a busy
 * one. Either crossing its threshold is `degraded`, never `down` - a backed-up queue is not an
 * outage, and paging someone at 3am for one would teach them to mute the alerts.
 *
 * Only measurable on the database driver. Redis and SQS keep their depth somewhere this package
 * has no business reaching into, so there it reports nothing rather than guessing.
 */
class QueueCheck implements Check
{
    use Timed;

    public function name(): string
    {
        return 'queue';
    }

    public function applies(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        try {
            return Schema::connection($this->connection())->hasTable($this->table());
        } catch (Throwable) {
            // No database means no queue answer, but the database check has already said so and
            // repeating it as a second red line would read as two separate failures.
            return false;
        }
    }

    public function run(): CheckResult
    {
        $startedAt = microtime(true);

        try {
            $pending = (int) DB::connection($this->connection())->table($this->table())->count();
            $oldest = DB::connection($this->connection())->table($this->table())->min('available_at');
        } catch (Throwable $exception) {
            return CheckResult::fromException($this->name(), $exception, $this->elapsed($startedAt));
        }

        $ms = $this->elapsed($startedAt);
        $waitedSeconds = $oldest === null ? 0 : max(0, time() - (int) $oldest);
        $summary = $pending.' '.($pending === 1 ? 'job' : 'jobs').' pending';

        $depthLimit = (int) config('cooee.health.queue.pending_threshold', 1000);
        $ageLimit = (int) config('cooee.health.queue.stale_after_seconds', 900);

        if ($pending > $depthLimit) {
            return CheckResult::degraded($this->name(), $summary.', above the '.$depthLimit.' threshold', $ms);
        }

        if ($waitedSeconds > $ageLimit) {
            return CheckResult::degraded(
                $this->name(),
                $summary.', oldest waiting '.$this->duration($waitedSeconds).' - is a worker running?',
                $ms,
            );
        }

        return new CheckResult($this->name(), Status::Ok, $summary, $ms);
    }

    private function connection(): ?string
    {
        return config('queue.connections.database.connection');
    }

    private function table(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        return $seconds < 3600
            ? intdiv($seconds, 60).'m'
            : intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}
