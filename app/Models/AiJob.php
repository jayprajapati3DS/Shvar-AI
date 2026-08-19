<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiJobStatus;
use App\Enums\AiJobType;
use App\Services\AI\Jobs\AiJobDurations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One piece of AI work you asked for, from request to result.
 *
 * The row is written before the job is queued and updated as it moves, so at no
 * point is there a request the interface cannot account for. That ordering
 * matters more than it looks: if the row were written by the worker, then a
 * queue with no worker running would show you nothing at all, and "nothing" is
 * indistinguishable from "it never started".
 *
 * @property AiJobType $type
 * @property AiJobStatus $status
 */
class AiJob extends Model
{
    protected $fillable = [
        'type',
        'status',
        'label',
        'subject_type',
        'subject_id',
        'payload',
        'result_url',
        'result_level',
        'result_summary',
        'error',
        'queued_at',
        'started_at',
        'finished_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AiJobType::class,
            'status' => AiJobStatus::class,
            'payload' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** The lead, company or reply this was about. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------------ */
    /* Scopes */
    /* ------------------------------------------------------------------ */

    /** Queued or running - still expected to produce something. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AiJobStatus::Queued->value,
            AiJobStatus::Running->value,
        ]);
    }

    /**
     * What the tray should show: everything unfinished, plus finished work the
     * user has not acknowledged.
     *
     * A finished job stays until dismissed rather than fading on a timer. The
     * whole point of running in the background is that you were doing something
     * else while it ran - a result that disappears after ten seconds is a result
     * you will miss.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle */
    /* ------------------------------------------------------------------ */

    public function markRunning(): void
    {
        $this->update([
            'status' => AiJobStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function markDone(string $summary, string $level = 'success'): void
    {
        $this->update([
            'status' => AiJobStatus::Done,
            'result_level' => $level,
            'result_summary' => $summary,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => AiJobStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Reading */
    /* ------------------------------------------------------------------ */

    /**
     * How long this has been going, or how long it took.
     *
     * Measured from when it was queued, not when a worker picked it up, because
     * that is the wait the person actually experienced.
     */
    public function elapsedSeconds(): float
    {
        $from = $this->started_at ?? $this->queued_at ?? $this->created_at;
        $to = $this->finished_at ?? Carbon::now();

        return $from === null ? 0.0 : max(0.0, (float) ($to->getTimestamp() - $from->getTimestamp()));
    }

    /**
     * A guess at how far through it is, 0 to 0.95.
     *
     * Capped below 1 on purpose. A local model returns one response at the end,
     * so there is no progress to report - this is elapsed time against a typical
     * run, and a bar that sits at 100% while the work continues would be a lie
     * told with a straight face. It eases towards the ceiling instead, which
     * reads as "still going, longer than usual" rather than "finished".
     */
    public function estimatedProgress(): float
    {
        if ($this->status === AiJobStatus::Queued) {
            return 0.0;
        }

        if ($this->status->isFinished()) {
            return 1.0;
        }

        // Measured from past runs on this machine where there are enough of
        // them, falling back to the written-down guess until there are.
        $typical = max(1, app(AiJobDurations::class)->typicalFor($this->type));
        $ratio = $this->elapsedSeconds() / $typical;

        // Asymptotic: 90% of the way at one typical run, creeping after that.
        return round(0.95 * (1 - exp(-1.8 * $ratio)), 3);
    }
}
