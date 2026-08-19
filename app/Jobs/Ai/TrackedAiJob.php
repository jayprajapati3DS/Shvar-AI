<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Models\AiJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The shared machinery for AI work that runs outside the browser request.
 *
 * Subclasses implement one method - do the work, return the sentence the user
 * should read - and this handles the rest: moving the row through its states,
 * turning an exception into a message a person can act on, and making sure the
 * row never ends up in a state the interface cannot explain.
 *
 * Two settings here are deliberate rather than inherited.
 *
 * `$tries = 1`. The queue's instinct is to retry a failed job, which is right
 * for sending an email and wrong for this. These jobs cost minutes of CPU, and
 * a model that produced unparseable JSON once will most likely do it again -
 * three times over is a quarter of an hour of fans spinning for the same
 * failure. If it should be retried, that is the user's call, and the tray gives
 * them a button.
 *
 * `$timeout` is an hour. Long enough that a slow CPU run is never killed
 * mid-sentence, short enough that a genuinely wedged job does not sit there for
 * the rest of the day claiming to be working.
 */
abstract class TrackedAiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $aiJobId) {}

    /**
     * Do the work.
     *
     * @return array{summary: string, level?: string, result_url?: string}
     */
    abstract protected function run(AiJob $job): array;

    public function handle(): void
    {
        $job = $this->record();

        // Cancelled while it sat in the queue, or the row was cleared out. Not
        // an error - just nothing left to do.
        if ($job === null || ! $job->status->isActive()) {
            return;
        }

        $job->markRunning();

        try {
            $outcome = $this->run($job);
        } catch (Throwable $e) {
            $job->markFailed($this->readable($e));

            // Reported, not rethrown. The trace still reaches the log, which is
            // what it is for - but the row above is now the record of the
            // failure, so there is nothing left for the queue to do with it.
            //
            // Rethrowing would also make `QUEUE_CONNECTION=sync` a trap. That is
            // a reasonable setting for someone who does not want a second
            // terminal, and under it the exception would come back out of the
            // browser request as a 500 instead of the message written above.
            report($e);

            // Nothing more to do. Falling through would reach markDone() below
            // and overwrite the failure with a success.
            return;
        }

        if (isset($outcome['result_url'])) {
            $job->update(['result_url' => $outcome['result_url']]);
        }

        $job->markDone($outcome['summary'], $outcome['level'] ?? 'success');
    }

    /**
     * The worker gave up on this - timeout, a killed process, a fatal error.
     *
     * handle() cannot catch those, so without this the row would sit at Running
     * for ever and the tray would show a spinner that never stops.
     */
    public function failed(?Throwable $e): void
    {
        $job = $this->record();

        if ($job === null || $job->status->isFinished()) {
            return;
        }

        $job->markFailed($e === null
            ? 'The background worker stopped before this finished.'
            : $this->readable($e));
    }

    protected function record(): ?AiJob
    {
        return AiJob::find($this->aiJobId);
    }

    /**
     * A message worth showing someone.
     *
     * The AI exceptions in this application already carry one written for a
     * person, along with a hint about what to do; anything else gets its class
     * name, because "Call to a member function on null" in a toast helps nobody
     * and the real stack trace is in the log either way.
     */
    private function readable(Throwable $e): string
    {
        if (method_exists($e, 'userMessage')) {
            $hint = method_exists($e, 'hint') ? (string) $e->hint() : '';

            return trim($e->userMessage().' '.$hint);
        }

        return sprintf('%s: %s', class_basename($e), $e->getMessage());
    }
}
