<?php

declare(strict_types=1);

namespace App\Services\AI\Jobs;

use App\Enums\AiJobStatus;
use App\Enums\AiJobType;
use App\Jobs\Ai\ClassifyEmailReply;
use App\Jobs\Ai\GenerateEmailDrafts;
use App\Jobs\Ai\ResearchCompanyWebsite;
use App\Jobs\Ai\RunLeadAnalysis;
use App\Jobs\Ai\TrackedAiJob;
use App\Models\AiJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one way AI work gets queued, and the one place that knows what is running.
 *
 * Controllers ask for a thing to happen and get a row back. They do not name job
 * classes, do not touch the queue, and do not decide what counts as a duplicate.
 */
class AiJobQueue
{
    /** @var array<string, class-string<TrackedAiJob>> */
    private const HANDLERS = [
        AiJobType::LeadAnalysis->value => RunLeadAnalysis::class,
        AiJobType::EmailGeneration->value => GenerateEmailDrafts::class,
        AiJobType::CompanyResearch->value => ResearchCompanyWebsite::class,
        AiJobType::ReplyClassification->value => ClassifyEmailReply::class,
    ];

    /**
     * Nothing older than this is worth showing in the tray on a fresh page load.
     *
     * Yesterday's finished analysis is history, not news.
     */
    private const VISIBLE_HOURS = 12;

    public function __construct(
        private readonly QueueWorkerStatus $worker,
    ) {}

    /**
     * Queue a piece of work and hand back the row that tracks it.
     *
     * The row is committed before the job is dispatched, in that order and via a
     * transaction, so a worker fast enough to pick the job up immediately cannot
     * find a row that is not there yet.
     *
     * @param  array<string, mixed>  $payload
     */
    public function push(
        AiJobType $type,
        Model $subject,
        string $label,
        string $resultUrl,
        array $payload = [],
    ): AiJob {
        $job = DB::transaction(fn (): AiJob => AiJob::create([
            'type' => $type,
            'status' => AiJobStatus::Queued,
            'label' => $label,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => $payload,
            'result_url' => $resultUrl,
            'queued_at' => now(),
        ]));

        $handler = self::HANDLERS[$type->value];

        dispatch(new $handler($job->id));

        return $job;
    }

    /**
     * Work of this kind already under way for this record, if any.
     *
     * The guard against queueing the same five-minute analysis three times
     * because the first click did not visibly do anything. Deliberately scoped
     * to type AND subject: analysing two different leads at once is fine, and
     * writing an email for a lead while analysing it is fine too.
     */
    public function activeFor(AiJobType $type, Model $subject): ?AiJob
    {
        return AiJob::query()
            ->active()
            ->where('type', $type->value)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * What the tray should show right now.
     *
     * @return Collection<int, AiJob>
     */
    public function visible(): Collection
    {
        $this->reapAbandoned();

        return AiJob::query()
            ->visible()
            ->where(function ($query): void {
                $query->active()
                    ->orWhere('finished_at', '>=', now()->subHours(self::VISIBLE_HOURS));
            })
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /** True while anything is queued or running. */
    public function hasActive(): bool
    {
        return AiJob::query()->active()->exists();
    }

    /**
     * Is work sitting in the queue with nothing coming to collect it?
     *
     * Three conditions, and all three are needed to avoid crying wolf.
     *
     * The worker has not polled recently - but that alone is not enough, because
     * a worker in the middle of a five-minute analysis is not polling either. So:
     * nothing is currently running, which rules that out. And something has been
     * waiting long enough that a healthy worker would have picked it up by now.
     *
     * Only then is the queue genuinely unattended, and only then is it worth
     * telling somebody.
     */
    public function looksStalled(): bool
    {
        if ($this->worker->online()) {
            return false;
        }

        $waiting = AiJob::query()
            ->where('status', AiJobStatus::Queued->value)
            ->where('queued_at', '<', now()->subSeconds(25))
            ->exists();

        if (! $waiting) {
            return false;
        }

        // A job in flight is proof of life, whatever the heartbeat says.
        return ! AiJob::query()
            ->where('status', AiJobStatus::Running->value)
            ->exists();
    }

    public function startCommand(): string
    {
        return $this->worker->startCommand();
    }

    /**
     * Close out jobs whose worker died without saying so.
     *
     * `failed()` covers an exception or a timeout, because the worker is alive to
     * run it. It cannot cover the terminal being closed mid-analysis, and that
     * is the common case on a laptop. Without this, a row from Tuesday sits at
     * Running for ever and the tray shows a spinner for work that stopped days
     * ago - which is worse than showing a failure, because it implies something
     * is still coming.
     *
     * The cutoff is generous: an analysis legitimately takes five minutes, so
     * only an hour of silence counts as abandoned.
     */
    private function reapAbandoned(): void
    {
        $abandoned = AiJob::query()
            ->where('status', AiJobStatus::Running->value)
            ->where('started_at', '<', now()->subHour());

        // Checked before writing. This runs on every page load and every poll,
        // and it is almost always a no-op - issuing the UPDATE regardless would
        // take a write lock on the database several times a second to change
        // nothing.
        if (! $abandoned->exists()) {
            return;
        }

        $abandoned->update([
            'status' => AiJobStatus::Failed->value,
            'error' => 'The background worker stopped before this finished. Nothing was saved. '
                .'Start it again and re-run this.',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
