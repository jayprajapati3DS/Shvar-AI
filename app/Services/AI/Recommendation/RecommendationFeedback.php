<?php

declare(strict_types=1);

namespace App\Services\AI\Recommendation;

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * What your corrections say about the recommendation engine.
 *
 * NOT training. The model's weights are frozen and nothing here touches them.
 * This reads back the corrections you are already making and feeds them into
 * the next prompt, the same way EmailStyleProfile does for writing.
 *
 * Three signals, and they mean different things:
 *
 *   MISSED    You attached a product by hand that the AI did not suggest. The
 *             strongest signal here by a distance: the model looked at this
 *             company and did not think of something you did.
 *
 *   REJECTED  The AI suggested it and you said no. Weaker on its own - a
 *             rejection can just mean "not this quarter" - but a product you
 *             reject repeatedly is one the model keeps misreading.
 *
 *   ACCEPTED  It suggested and you agreed. The baseline. Only interesting as
 *             the denominator: a product accepted nine times in ten is doing
 *             fine and needs no correction.
 *
 * All of it is per-product rather than per-company, because that is the level a
 * correction generalises at. "You keep missing MySegmenter for imaging
 * companies" is useful next time; "you missed it for Craniofax" is not.
 *
 * Deliberately inspectable and switchable, for the same reason as the email
 * profile: a prompt that silently rewrites itself is how a system quietly gets
 * worse in ways nobody can point at.
 */
class RecommendationFeedback
{
    /** Below this there is not enough signal to correct anything. */
    public const MIN_CORRECTIONS = 2;

    /** How many analysed leads to look back over. */
    private const WINDOW = 100;

    /** Products named in the prompt. More turns guidance into noise. */
    private const MAX_NOTES = 5;

    /** @var Collection<int, LeadProductMatch>|null */
    private ?Collection $cache = null;

    /**
     * Every product decision on a lead the AI actually analysed.
     *
     * The filter matters: a manual pick on a lead that was never analysed is
     * not a correction, it is just data entry. Only a lead the model had a go
     * at can tell you the model got it wrong.
     *
     * @return Collection<int, LeadProductMatch>
     */
    private function decisions(): Collection
    {
        return $this->cache ??= LeadProductMatch::query()
            ->whereHas('lead', fn ($q) => $q->whereHas('analyses'))
            ->with(['product:id,name', 'lead:id,company_id'])
            ->latest('id')
            ->limit(self::WINDOW * 3)
            ->get();
    }

    /**
     * Products you added that the AI had not suggested for that lead.
     *
     * @return array<int, array{product: string, count: int}>
     */
    public function missed(): array
    {
        $counts = [];

        foreach ($this->decisions() as $match) {
            if ($match->recommendation_type !== RecommendationType::Manual) {
                continue;
            }

            // Only a miss if the AI had a chance and did not name it. If a
            // later analysis DID suggest the same product for this lead, the
            // model had it - the manual row just came first.
            if ($this->aiSuggestedFor($match->lead_id, $match->product_id)) {
                continue;
            }

            $name = $match->product?->name;

            if ($name === null) {
                continue;
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        arsort($counts);

        return array_map(
            fn (string $name) => ['product' => $name, 'count' => $counts[$name]],
            array_keys($counts),
        );
    }

    /**
     * Products the AI suggested that you turned down.
     *
     * @return array<int, array{product: string, count: int, accepted: int}>
     */
    public function rejected(): array
    {
        $rejected = [];
        $accepted = [];

        foreach ($this->decisions() as $match) {
            if ($match->recommendation_type === RecommendationType::Manual) {
                continue;
            }

            $name = $match->product?->name;

            if ($name === null) {
                continue;
            }

            if ($match->status === RecommendationStatus::Rejected) {
                $rejected[$name] = ($rejected[$name] ?? 0) + 1;
            } elseif ($match->status === RecommendationStatus::Accepted) {
                $accepted[$name] = ($accepted[$name] ?? 0) + 1;
            }
        }

        arsort($rejected);

        $out = [];

        foreach ($rejected as $name => $count) {
            // A product rejected twice and accepted eight times is not a
            // problem with the model, it is a normal spread. Only report one
            // that is turned down more often than it is taken.
            if ($count <= ($accepted[$name] ?? 0)) {
                continue;
            }

            $out[] = [
                'product' => $name,
                'count' => $count,
                'accepted' => $accepted[$name] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Whether there is enough to say anything.
     *
     * Counts corrections, not distinct products. Adding the same product three
     * times that the model kept missing is a strong, actionable signal - and
     * measuring distinct products would have called that one data point.
     */
    public function hasEnough(): bool
    {
        return $this->correctionCount() >= self::MIN_CORRECTIONS;
    }

    public function correctionCount(): int
    {
        return array_sum(array_column($this->missed(), 'count'))
            + array_sum(array_column($this->rejected(), 'count'));
    }

    /**
     * Render the corrections as a prompt block, or null when there is nothing
     * worth saying.
     *
     * Placed after the portfolio and before the task, and framed as MY
     * corrections rather than as rules - the model still has to justify a
     * recommendation from the company data. A hint that this product is often
     * missed must not become a licence to recommend it to everybody.
     */
    public function promptBlock(): ?string
    {
        if (! $this->hasEnough()) {
            return null;
        }

        $lines = [
            '=== WHAT I HAVE CORRECTED BEFORE ===',
            '',
            'These are decisions I made on your past recommendations, across '
                .$this->correctionCount().' product(s).',
            'Treat them as a hint about where you tend to go wrong - NOT as a rule.',
            'You must still justify every recommendation from THIS company\'s data. Never recommend',
            'a product because it appears below; a hint is not evidence.',
            '',
        ];

        $missed = array_slice($this->missed(), 0, self::MAX_NOTES);

        if ($missed !== []) {
            $lines[] = 'Products I added MYSELF after you had analysed the lead, meaning you did not';
            $lines[] = 'think of them. Consider whether they fit here too:';

            foreach ($missed as $item) {
                $lines[] = sprintf(
                    '  - %s (I added it %d time%s you missed it)',
                    $item['product'],
                    $item['count'],
                    $item['count'] === 1 ? '' : 's',
                );
            }

            $lines[] = '';
        }

        $rejected = array_slice($this->rejected(), 0, self::MAX_NOTES);

        if ($rejected !== []) {
            $lines[] = 'Products you suggest that I usually turn down. Be stricter with these -';
            $lines[] = 'recommend them only when the company data makes the case clearly:';

            foreach ($rejected as $item) {
                $lines[] = sprintf(
                    '  - %s (rejected %d, accepted %d)',
                    $item['product'],
                    $item['count'],
                    $item['accepted'],
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Everything learned, for the settings screen.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active' => $this->hasEnough(),
            'corrections' => $this->correctionCount(),
            'min_corrections' => self::MIN_CORRECTIONS,
            'missed' => $this->missed(),
            'rejected' => $this->rejected(),
            'prompt_block' => $this->promptBlock(),
        ];
    }

    /* ---------------------------------------------------------------------- */

    /** Whether any AI analysis of this lead named this product. */
    private function aiSuggestedFor(int $leadId, ?int $productId): bool
    {
        if ($productId === null) {
            return false;
        }

        return $this->decisions()->contains(
            fn (LeadProductMatch $m) => $m->lead_id === $leadId
                && $m->product_id === $productId
                && $m->recommendation_type !== RecommendationType::Manual,
        );
    }
}
