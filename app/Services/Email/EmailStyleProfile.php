<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Models\EmailDraft;
use Illuminate\Support\Collection;

/**
 * What the application has learned about how you actually write.
 *
 * WHAT THIS IS NOT: training. The model's weights are frozen and nothing here
 * touches them. There is no fine-tuning, no gradient, no upload.
 *
 * WHAT IT IS: the signal you are already producing, fed back into the prompt.
 * Every time you approve one variant over another, shorten a draft, or rewrite
 * an opening, you are stating a preference. Until now that was thrown away and
 * the next generation started from the same blank slate. This reads it back:
 *
 *   - which variant you approve most      -> the model favours that register
 *   - how long your approved emails are   -> a real number, not the setting
 *   - the phrases you consistently delete  -> named and banned
 *   - your best approved emails            -> included as worked examples
 *
 * Learned only from drafts you APPROVED or SENT. A draft you rejected is not a
 * style preference, and one still sitting in Draft is just the model's own
 * output - learning from that would be the model teaching itself, which
 * compounds its habits instead of correcting them.
 *
 * Deliberately inspectable and resettable. A prompt that silently rewrites
 * itself is how a system quietly gets worse in ways nobody can point at, so
 * Settings shows exactly what was learned and can turn it off.
 */
class EmailStyleProfile
{
    /** Below this there is not enough signal to say anything useful. */
    public const MIN_SAMPLES = 3;

    /**
     * Before this many, a word count is noise rather than a habit.
     *
     * Higher than MIN_SAMPLES on purpose. A median of three is one short email
     * away from claiming you write 40-word emails - and unlike a worked example,
     * which the model can weigh against everything else, a stated number reads
     * as an instruction and gets followed.
     */
    public const MIN_SAMPLES_FOR_LENGTH = 6;

    /** Worked examples included in the prompt. More crowds out the actual data. */
    public const MAX_EXAMPLES = 2;

    /** How many approved emails to learn from. Recent behaviour, not ancient. */
    private const WINDOW = 40;

    /** @var Collection<int, EmailDraft>|null */
    private ?Collection $cache = null;

    public function __construct(
        private readonly EmailSettings $settings,
    ) {}

    /**
     * The drafts worth learning from: approved or sent, newest first.
     *
     * @return Collection<int, EmailDraft>
     */
    private function samples(): Collection
    {
        return $this->cache ??= EmailDraft::query()
            ->whereIn('status', [
                EmailDraftStatus::Approved->value,
                EmailDraftStatus::Sent->value,
            ])
            ->whereNotNull('body')
            ->latestFirst()
            ->limit(self::WINDOW)
            ->get();
    }

    public function sampleCount(): int
    {
        return $this->samples()->count();
    }

    /** Whether there is enough history to say anything. */
    public function hasEnough(): bool
    {
        return $this->settings->learningEnabled()
            && $this->sampleCount() >= self::MIN_SAMPLES;
    }

    /* ---------------------------------------------------------------------- */
    /* What was learned */
    /* ---------------------------------------------------------------------- */

    /**
     * The variant you approve most often.
     *
     * Null when no style is clearly ahead - a dead heat is not a preference,
     * and telling the model to favour a style you pick 34% of the time would be
     * inventing a signal rather than reading one.
     */
    public function preferredVariant(): ?EmailVariant
    {
        $counts = $this->samples()
            ->groupBy(fn (EmailDraft $d) => $d->variant->value)
            ->map->count()
            ->sortDesc();

        if ($counts->count() < 2) {
            return $counts->isEmpty() ? null : EmailVariant::tryFrom((string) $counts->keys()->first());
        }

        $top = $counts->first();
        $second = $counts->slice(1, 1)->first();

        // A clear lead means at least half again as many as the runner-up.
        return $top >= $second * 1.5
            ? EmailVariant::tryFrom((string) $counts->keys()->first())
            : null;
    }

    /**
     * The median word count of what you actually approve.
     *
     * Null until there is enough to be worth stating - see
     * MIN_SAMPLES_FOR_LENGTH.
     */
    public function typicalWordCount(): ?int
    {
        if ($this->sampleCount() < self::MIN_SAMPLES_FOR_LENGTH) {
            return null;
        }

        $counts = $this->samples()
            ->map(fn (EmailDraft $d) => $d->word_count ?? EmailDraft::countWords((string) $d->body))
            ->filter(fn (int $n) => $n > 0)
            ->sort()
            ->values();

        if ($counts->isEmpty()) {
            return null;
        }

        // Median, not mean: one 400-word outlier should not move the target.
        $middle = (int) floor($counts->count() / 2);

        return (int) $counts[$middle];
    }

    /** How often you rewrite the model's wording at all, 0.0-1.0. */
    public function editRate(): float
    {
        $samples = $this->samples();

        if ($samples->isEmpty()) {
            return 0.0;
        }

        return round(
            $samples->filter(fn (EmailDraft $d) => $d->wasEdited())->count() / $samples->count(),
            2,
        );
    }

    /**
     * Sentences you consistently delete from the model's drafts.
     *
     * Computed by diffing ai_body against body on edited drafts: anything that
     * was in the model's version and not in yours, appearing more than once
     * across different emails, is a habit rather than a one-off.
     *
     * This is the most useful thing here. "Stop writing X" is a far stronger
     * instruction than any amount of style guidance, because it is specific and
     * it came from you.
     *
     * @return list<string>
     */
    public function rejectedPhrases(): array
    {
        $counts = [];

        foreach ($this->samples() as $draft) {
            if (! $draft->wasEdited() || $draft->ai_body === null) {
                continue;
            }

            $kept = $this->sentences((string) $draft->body);

            foreach ($this->sentences((string) $draft->ai_body) as $sentence) {
                if (in_array($sentence, $kept, true)) {
                    continue;
                }

                // Normalised so "Hi Dana," and "Hi Léa," do not count as
                // different habits - it is the shape being rejected, not the name.
                $key = $this->normalise($sentence);

                if (mb_strlen($key) < 20) {
                    continue;
                }

                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);

        return array_values(array_slice(
            array_keys(array_filter($counts, fn (int $n) => $n >= 2)),
            0,
            5,
        ));
    }

    /**
     * Your best approved emails, as worked examples.
     *
     * Prefers ones you edited: a draft you rewrote and then approved is a much
     * stronger statement of how you want it to read than one you accepted as-is.
     *
     * @return list<array{subject: string, body: string, edited: bool}>
     */
    public function examples(): array
    {
        return $this->samples()
            ->sortByDesc(fn (EmailDraft $d) => $d->wasEdited() ? 1 : 0)
            ->take(self::MAX_EXAMPLES)
            ->map(fn (EmailDraft $d) => [
                'subject' => (string) $d->subject,
                'body' => (string) $d->body,
                'edited' => $d->wasEdited(),
            ])
            ->values()
            ->all();
    }

    /* ---------------------------------------------------------------------- */
    /* The prompt block */
    /* ---------------------------------------------------------------------- */

    /**
     * Render everything learned as a prompt block, or null when there is not
     * enough to say.
     *
     * Placed after the company and product data on purpose: this is guidance
     * about HOW to write, and it must never outrank the rules about WHAT is
     * true. A worked example showing a claim is still a claim that has to come
     * from the product record.
     */
    public function promptBlock(): ?string
    {
        if (! $this->hasEnough()) {
            return null;
        }

        $lines = [
            '=== HOW I ACTUALLY WRITE (learned from '.$this->sampleCount().' emails I approved) ===',
            '',
            'These are my demonstrated preferences, not rules about what is true.',
            'Follow them - but never at the expense of the truth rules above.',
            '',
        ];

        if ($variant = $this->preferredVariant()) {
            $lines[] = sprintf(
                '- I approve the %s style most often. Weight all three that way.',
                $variant->shortLabel(),
            );
        }

        if ($words = $this->typicalWordCount()) {
            $lines[] = sprintf(
                '- My approved emails run about %d words. That is the real target, whatever the '
                .'length setting says.',
                $words,
            );
        }

        $rejected = $this->rejectedPhrases();

        if ($rejected !== []) {
            $lines[] = '- I consistently DELETE sentences like these. Do not write them:';

            foreach ($rejected as $phrase) {
                $lines[] = '    "'.mb_strimwidth($phrase, 0, 120, '…').'"';
            }
        }

        $examples = $this->examples();

        if ($examples !== []) {
            $lines[] = '';
            $lines[] = 'Emails I approved and sent. Match this register, not their content:';

            foreach ($examples as $index => $example) {
                $lines[] = '';
                $lines[] = sprintf(
                    '--- example %d%s ---',
                    $index + 1,
                    $example['edited'] ? ' (I rewrote this one before approving it)' : '',
                );
                $lines[] = 'Subject: '.$example['subject'];
                $lines[] = $example['body'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Everything learned, for the settings screen.
     *
     * The point of this method is that the learning is not a black box: you can
     * read exactly what it concluded and turn it off if it concluded something
     * silly.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $variant = $this->preferredVariant();

        return [
            'enabled' => $this->settings->learningEnabled(),
            'samples' => $this->sampleCount(),
            'min_samples' => self::MIN_SAMPLES,
            'active' => $this->hasEnough(),
            'preferred_variant' => $variant?->value,
            'preferred_variant_label' => $variant?->label(),
            'typical_word_count' => $this->typicalWordCount(),
            'min_samples_for_length' => self::MIN_SAMPLES_FOR_LENGTH,
            'edit_rate' => $this->editRate(),
            'rejected_phrases' => $this->rejectedPhrases(),
            'example_count' => count($this->examples()),
            'prompt_block' => $this->promptBlock(),
        ];
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Split into comparable sentences.
     *
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+|\n+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $parts),
            fn (string $s) => $s !== '',
        ));
    }

    /** Collapse whitespace so trivially different lines compare equal. */
    private function normalise(string $sentence): string
    {
        return trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence);
    }
}
