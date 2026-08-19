<?php

declare(strict_types=1);

namespace App\Services\AI\Jobs;

use Illuminate\Support\Facades\Cache;

/**
 * Is anything actually out there to run the work?
 *
 * Background processing has one failure mode that dwarfs the rest: the queue
 * worker is not running. Nothing errors, nothing logs, the job simply sits in
 * the table for ever while a spinner turns. On a laptop this is not an exotic
 * case - it is what happens whenever the app is started with `artisan serve`
 * alone instead of `composer dev`.
 *
 * So the worker leaves a note each time it looks for work, and the interface
 * reads it. A stale note plus a job that has been waiting is the one situation
 * worth interrupting the user about, because the fix is a command they can
 * paste and the alternative is waiting for something that will never happen.
 *
 * The heartbeat goes through the cache rather than a table of its own: it is
 * genuinely ephemeral, and the cache store here is the database anyway, so it
 * crosses the process boundary between worker and web request as required.
 */
class QueueWorkerStatus
{
    private const KEY = 'ai.queue_worker.last_seen';

    /**
     * Seconds of silence before a worker is presumed gone.
     *
     * Well clear of the default three-second poll, so a worker that is merely
     * between polls is never reported as missing.
     */
    private const STALE_AFTER = 60;

    /** Called by the worker itself, each time round its loop. */
    public function heartbeat(): void
    {
        Cache::put(self::KEY, time(), now()->addMinutes(10));
    }

    /**
     * Has a worker looked for work recently?
     *
     * A false here does NOT on its own mean the worker is gone. While it is busy
     * inside a five-minute analysis it is not looping, so it leaves no note -
     * which is why the caller weighs this against whether anything is currently
     * running before telling the user anything is wrong.
     */
    public function online(): bool
    {
        $seen = $this->lastSeen();

        return $seen !== null && (time() - $seen) <= self::STALE_AFTER;
    }

    /** Unix timestamp of the last poll, or null if a worker has never run. */
    public function lastSeen(): ?int
    {
        $seen = Cache::get(self::KEY);

        return is_int($seen) ? $seen : null;
    }

    /** The command to start one, for the message shown when there is none. */
    public function startCommand(): string
    {
        return 'php artisan queue:work --tries=1 --timeout=3600';
    }
}
