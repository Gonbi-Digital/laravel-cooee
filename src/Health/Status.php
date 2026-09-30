<?php

namespace GonbiDigital\Cooee\Health;

/**
 * The three words a health report is allowed to say.
 *
 * Deliberately not a boolean. "Up or down" cannot express a cache that has gone away while pages
 * still serve, and collapsing that into `down` would put a red banner in front of a client whose
 * site is working - while collapsing it into `up` throws away the half hour before a filling disk
 * becomes an outage.
 */
enum Status: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';

    /**
     * The overall verdict: the worst component wins.
     *
     * A report is only as good as its unhappiest part. Averaging, or reporting the most common
     * status, would let one dead database hide behind three healthy services.
     *
     * @param  iterable<Status>  $statuses
     */
    public static function worst(iterable $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status === self::Down) {
                return self::Down;
            }

            if ($status === self::Degraded) {
                $worst = self::Degraded;
            }
        }

        return $worst;
    }

    /**
     * The HTTP status to answer with.
     *
     * `degraded` answers 200, and that is the whole point of having three words: the consumer is
     * told something is wrong without being told the app is unusable. A 503 here would make every
     * monitor in front of this app declare an outage over a queue backlog.
     */
    public function httpStatus(): int
    {
        return $this === self::Down ? 503 : 200;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Healthy',
            self::Degraded => 'Degraded',
            self::Down => 'Down',
        };
    }
}
