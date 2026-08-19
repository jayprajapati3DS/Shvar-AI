<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiJobStatus;
use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Resources\AiJobResource;
use App\Models\AiJob;
use App\Services\AI\Jobs\AiJobQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * The activity tray: what the AI is doing, and what it just finished.
 *
 * The polling endpoint returns plain JSON rather than an Inertia response on
 * purpose. Polling every couple of seconds through Inertia would rewrite the
 * page props and push history entries, so the back button would walk through
 * fifty identical states of the page you are sitting on. This is a read of a
 * small table and nothing more.
 */
class AiJobController extends Controller
{
    use RedirectsToOrigin;

    public function __construct(
        private readonly AiJobQueue $queue,
    ) {}

    /** Current state of the tray, for the browser's poll. */
    public function index(): JsonResponse
    {
        return response()->json(self::payload($this->queue));
    }

    /**
     * Everything the tray needs, shared with page loads as well as polls.
     *
     * Static and taking its dependency as an argument so the Inertia middleware
     * can call it without duplicating the shape. One definition means the tray
     * cannot render differently depending on how it was told.
     *
     * @return array<string, mixed>
     */
    public static function payload(AiJobQueue $queue): array
    {
        $jobs = $queue->visible();

        return [
            'jobs' => $jobs->map(fn (AiJob $job) => (new AiJobResource($job))->resolve())->all(),
            'active' => $jobs->filter(fn (AiJob $job) => $job->status->isActive())->count(),

            // True only when work is genuinely sitting unattended - not merely
            // because a worker is busy. See AiJobQueue::looksStalled().
            'stalled' => $queue->looksStalled(),
            'start_command' => $queue->startCommand(),
        ];
    }

    /** Close a finished job. It stays in the table as history. */
    public function dismiss(AiJob $aiJob): RedirectResponse
    {
        if ($aiJob->status->isFinished()) {
            $aiJob->update(['dismissed_at' => now()]);
        }

        return $this->backTo('dashboard');
    }

    /** Close every finished job at once. */
    public function dismissAll(): RedirectResponse
    {
        AiJob::query()
            ->visible()
            ->whereNotIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value])
            ->update(['dismissed_at' => now(), 'updated_at' => now()]);

        return $this->backTo('dashboard');
    }

    /**
     * Take something out of the queue before a worker reaches it.
     *
     * Only while it is still waiting. Once the model is generating there is
     * nothing to cancel that would not waste the minutes already spent - the
     * request is in flight and will finish whether or not anybody is watching -
     * so the honest options at that point are to let it land or to dismiss the
     * result afterwards.
     *
     * The queued job itself is left where it is rather than hunted down in the
     * jobs table; it checks this row when it wakes and returns having done
     * nothing.
     */
    public function cancel(AiJob $aiJob): RedirectResponse
    {
        if ($aiJob->status !== AiJobStatus::Queued) {
            return $this->backTo('dashboard')->with(
                'info',
                'That has already started. It cannot be stopped part-way, but you can dismiss the result.',
            );
        }

        $aiJob->update([
            'status' => AiJobStatus::Cancelled,
            'finished_at' => now(),
        ]);

        return $this->backTo('dashboard')->with('info', 'Removed from the queue.');
    }
}
