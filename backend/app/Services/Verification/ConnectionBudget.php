<?php

namespace App\Services\Verification;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A hard ceiling on outbound SMTP connections per hour.
 *
 * The per-domain delay and worker count only shape the rate indirectly:
 * the actual figure depends on how many domains happen to hold work at
 * once, so it drifts from a few hundred an hour to a few thousand purely
 * on the shape of what was last imported. Contabo flagged exactly that
 * spike — 3,908 connections in one hour — and a limit that emerges from
 * three interacting knobs is not something you can promise a provider.
 *
 * This makes the rate a number you set rather than one you observe.
 * Queue as much work as you like; the dialling rate is unaffected.
 *
 * Counted in the cache rather than by querying smtp_logs, which has no
 * index on created_at and is heading for tens of millions of rows — a
 * range scan on every poll would cost far more than the checks it guards.
 */
class ConnectionBudget
{
    public function __construct(private readonly int $maxPerHour)
    {
    }

    /** Connections already used in the current clock hour. */
    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    public function limit(): int
    {
        return $this->maxPerHour;
    }

    public function remaining(): int
    {
        return max(0, $this->maxPerHour - $this->used());
    }

    public function exhausted(): bool
    {
        return $this->maxPerHour > 0 && $this->used() >= $this->maxPerHour;
    }

    /**
     * Records that a connection is about to be made.
     *
     * Incremented before dialling, not after: a crash mid-connection
     * should still cost budget, since the remote server saw the
     * connection regardless of what happened on our side.
     */
    public function consume(int $count = 1): void
    {
        $key = $this->key();

        // add() seeds the key with a TTL only if absent; increment() alone
        // on a missing key would create one with no expiry and the budget
        // would never reset.
        Cache::add($key, 0, Carbon::now()->addMinutes(90));
        Cache::increment($key, $count);
    }

    public function secondsUntilReset(): int
    {
        return max(1, Carbon::now()->diffInSeconds(Carbon::now()->endOfHour(), false));
    }

    /**
     * Bucketed by clock hour, so the allowance resets on the hour rather
     * than sliding. Simpler to reason about and to explain to a provider
     * than a rolling window, at the cost of allowing two adjacent hours
     * to place their usage back to back.
     */
    private function key(): string
    {
        return 'smtp-connections:'.Carbon::now()->format('YmdH');
    }
}
