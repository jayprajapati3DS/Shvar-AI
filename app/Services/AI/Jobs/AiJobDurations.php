<?php

declare(strict_types=1);

namespace App\Services\AI\Jobs;

use App\Enums\AiJobStatus;
use App\Enums\AiJobType;
use App\Models\AiJob;

/**
 * How long this kind of work actually takes, on this machine.
 *
 * The progress bar needs a number to measure elapsed time against, and a
 * hardcoded one is a guess about somebody else's hardware. The first real run
 * here made that obvious: reading a reply was written down as thirty seconds and
 * took two minutes eighteen on qwen3:4b, which would have parked the bar at its
 * ceiling for most of the job.
 *
 * So it reads the past instead. Same shape as the other learning in this
 * application - nothing is trained, nothing is inferred; it is the median of
 * what already happened, and it falls back to the written-down estimate until
 * there is enough of a history to beat it.
 *
 * The median rather than the mean, because one job that ran while the laptop was
 * compiling something should not drag every future estimate with it.
 */
class AiJobDurations
{
    /**
     * How many past runs before measurement beats the guess.
     *
     * Three is low, and deliberately so: the written-down numbers are known to
     * be wrong on any machine but the one they were measured on, so almost any
     * local evidence is an improvement.
     */
    private const MIN_SAMPLES = 3;

    /** Only recent runs count - a model or hardware change should age out. */
    private const SAMPLE_SIZE = 10;

    /** @var array<string, int> */
    private array $cache = [];

    public function typicalFor(AiJobType $type): int
    {
        return $this->cache[$type->value] ??= $this->measure($type);
    }

    private function measure(AiJobType $type): int
    {
        $durations = AiJob::query()
            ->where('type', $type->value)
            ->where('status', AiJobStatus::Done->value)
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->latest('id')
            ->limit(self::SAMPLE_SIZE)
            ->get()
            ->map(fn (AiJob $job) => $job->finished_at->getTimestamp() - $job->started_at->getTimestamp())
            // A run that finished in under a second did not reach the model -
            // a deleted subject, a withdrawn recommendation. Including those
            // would tell the bar that an analysis takes no time at all.
            ->filter(fn (int $seconds) => $seconds >= 2)
            ->sort()
            ->values();

        if ($durations->count() < self::MIN_SAMPLES) {
            return $type->typicalSeconds();
        }

        return (int) $durations->get(intdiv($durations->count(), 2));
    }
}
