<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\RecommendationStatus;
use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Resources\LeadAnalysisResource;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\LeadProductMatch;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Recommendation\AiProductMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AI Sales Intelligence on the lead page.
 *
 * The controller stays thin: it resolves the lead, delegates to
 * AiProductMatcher, and turns an AiException into a friendly message. It has no
 * knowledge of prompts, Ollama, or JSON parsing.
 *
 * Every recommendation the AI produces lands as Suggested. Only the accept and
 * reject actions here move it, and only when the user asks - nothing in this
 * class changes a lead's status, priority or stage.
 */
class LeadAnalysisController extends Controller
{
    use RedirectsToOrigin;

    public function __construct(
        private readonly AiProductMatcher $matcher,
    ) {}

    /**
     * Run a fresh analysis.
     *
     * Always additive: a previous analysis is never modified or deleted, so
     * "Regenerate" builds history rather than replacing it.
     */
    public function store(Lead $lead): RedirectResponse
    {
        try {
            $analysis = $this->matcher->analyse($lead);
        } catch (AiException $e) {
            // The attempt is already in the local AI log by this point.
            return $this->backTo('leads.show', $lead->id)->with('error', trim($e->userMessage().' '.($e->hint() ?? '')));
        }

        $count = $analysis->recommendations()->count();

        // An empty result is a legitimate outcome, not a failure - the model
        // deciding this company is outside the market. Say so plainly.
        if ($count === 0) {
            return $this->backTo('leads.show', $lead->id)->with(
                'info',
                'Analysis complete: the local model found no product in the portfolio that the stored '
                .'information supports recommending.',
            );
        }

        return $this->backTo('leads.show', $lead->id)->with('success', sprintf(
            'Analysis complete: %d recommendation(s) from %s in %ss. Review and accept the ones you want.',
            $count,
            $analysis->model,
            number_format((float) $analysis->seconds(), 1),
        ));
    }

    /**
     * Full detail for one past analysis, for the history viewer.
     *
     * A JSON endpoint rather than page props: the history list only needs
     * summaries, and shipping every past analysis in full would bloat the lead
     * page for a lead analysed a dozen times.
     */
    public function show(Lead $lead, LeadAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->lead_id === $lead->id, 404);

        $analysis->load(['recommendations.product', 'primaryProduct']);

        return response()->json([
            'analysis' => (new LeadAnalysisResource($analysis))->resolve(),
        ]);
    }

    /**
     * Accept a recommendation.
     *
     * This is the only path by which an AI suggestion becomes a real product
     * opportunity on the lead. It records an activity so the timeline shows a
     * human made the call, and when.
     */
    public function accept(Request $request, Lead $lead, LeadProductMatch $match): RedirectResponse
    {
        abort_unless($match->lead_id === $lead->id, 404);

        $match->loadMissing('product');

        // Idempotent. A retried request - or a click whose response the browser
        // never saw - must not log a second "accepted" entry on the timeline.
        if ($match->status === RecommendationStatus::Accepted) {
            return $this->backTo('leads.show', $lead->id)->with('info', sprintf(
                '%s was already accepted.',
                $match->product?->name ?? 'That recommendation',
            ));
        }

        $match->update([
            'status' => RecommendationStatus::Accepted,
            'reviewed_at' => now(),
        ]);

        Activity::record(
            $lead,
            ActivityType::Note,
            'Accepted AI product recommendation',
            sprintf(
                '%s%s accepted at %d%% confidence.',
                $match->product?->name ?? 'Product',
                $match->module ? " ({$match->module})" : '',
                $match->confidencePercent() ?? 0,
            ),
        );

        return $this->backTo('leads.show', $lead->id)->with('success', sprintf(
            '%s accepted as a product opportunity.',
            $match->product?->name ?? 'Recommendation',
        ));
    }

    /**
     * Reject a recommendation.
     *
     * Kept, not deleted - a rejected suggestion is useful history, and stops the
     * same idea being re-litigated after the next analysis.
     */
    public function reject(Lead $lead, LeadProductMatch $match): RedirectResponse
    {
        abort_unless($match->lead_id === $lead->id, 404);

        $match->update([
            'status' => RecommendationStatus::Rejected,
            'reviewed_at' => now(),
        ]);

        return $this->backTo('leads.show', $lead->id)->with('success', 'Recommendation rejected. It stays in the history.');
    }

    /** Move a recommendation out of the way without judging it. */
    public function archive(Lead $lead, LeadProductMatch $match): RedirectResponse
    {
        abort_unless($match->lead_id === $lead->id, 404);

        $match->update([
            'status' => RecommendationStatus::Archived,
            'reviewed_at' => now(),
        ]);

        return $this->backTo('leads.show', $lead->id)->with('success', 'Recommendation archived.');
    }
}
