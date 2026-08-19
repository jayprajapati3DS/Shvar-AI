<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RecommendationStatus;
use App\Http\Requests\GenerateEmailRequest;
use App\Models\EmailGeneration;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Services\AI\Exceptions\AiException;
use App\Services\Email\EmailDraftEditor;
use App\Services\Email\EmailGenerator;
use Illuminate\Http\RedirectResponse;

/**
 * Generating outreach emails from the lead page.
 *
 * Stays thin, like LeadAnalysisController: it resolves the lead and the
 * recommendation, delegates to EmailGenerator, and turns an AiException into a
 * friendly message. It knows nothing about prompts, Ollama or JSON.
 *
 * Two things it enforces that the generator cannot:
 *
 *   1. The recommendation must be one the user ACCEPTED, and it must belong to
 *      this lead. Section 5 of the brief - the email follows the approved sales
 *      direction rather than the model picking a product for itself.
 *   2. There must be somebody to write to. A lead with no email address
 *      cannot produce a sendable email, so it does not produce one at all.
 */
class EmailGenerationController extends Controller
{
    public function __construct(
        private readonly EmailGenerator $generator,
        private readonly EmailDraftEditor $editor,
    ) {}

    /**
     * Generate three variants for a lead.
     *
     * Always additive: a previous generation is never modified or deleted, so
     * regenerating builds history rather than replacing it.
     */
    public function store(GenerateEmailRequest $request, Lead $lead): RedirectResponse
    {
        // The primary first, then any secondaries in the order they were chosen.
        // Order is meaningful all the way down: the model is told to lead with
        // the first one.
        $set = [];

        foreach ([
            $request->integer('recommendation_id'),
            ...array_map(intval(...), (array) $request->input('secondary_recommendation_ids', [])),
        ] as $id) {
            $resolved = $this->resolveRecommendation($lead, $id);

            if (is_string($resolved)) {
                return back()->with('error', $resolved);
            }

            $set[] = $resolved;
        }

        if ($blocker = $this->missingEssentials($lead)) {
            return back()->with('error', $blocker);
        }

        $replacing = $this->resolveReplacing($lead, $request->integer('regenerate_from'));

        try {
            $generation = $this->generator->generate(
                $lead,
                $set,
                $request->input('extra_instructions'),
                $replacing,
            );
        } catch (AiException $e) {
            // The attempt is already in the local AI log by this point.
            return back()->with('error', trim($e->userMessage().' '.($e->hint() ?? '')));
        }

        $count = $generation->drafts()->count();
        $products = $generation->recommendations()->count();

        $message = sprintf(
            '%d draft(s) about %s, written by %s in %ss. Nothing has been sent - review, edit and approve first.',
            $count,
            $products === 1
                ? ($set[0]->product?->name ?? 'the selected product')
                : $products.' products',
            $generation->model,
            number_format($generation->seconds(), 1),
        );

        if ($replacing !== null) {
            $message .= ' Compare them against the previous version before choosing.';
        }

        return back()->with('success', $message);
    }

    /**
     * Resolve a regeneration: keep one run, archive the other's open drafts.
     *
     * Nothing is deleted either way - a regeneration you rejected stays
     * readable, which is the point of keeping versions at all.
     */
    public function resolve(Lead $lead, EmailGeneration $generation, string $choice): RedirectResponse
    {
        abort_unless($generation->lead_id === $lead->id, 404);

        $previous = $generation->regeneratedFrom;

        if ($previous === null) {
            return back()->with('error', 'That generation did not replace an earlier one.');
        }

        [$keep, $discard] = $choice === 'previous'
            ? [$previous, $generation]
            : [$generation, $previous];

        $this->editor->resolveRegeneration($keep, $discard);

        return back()->with('success', $choice === 'previous'
            ? 'Kept the previous version. The new drafts were archived, not deleted.'
            : 'Using the new version. The previous drafts were archived, not deleted.');
    }

    /**
     * The recommendation this email must be written from.
     *
     * Returns an error string rather than throwing: these are all "you need to
     * do something first" situations, not failures.
     */
    private function resolveRecommendation(Lead $lead, int $recommendationId): LeadProductMatch|string
    {
        if ($recommendationId <= 0) {
            return 'Choose which accepted product recommendation this email should be about.';
        }

        $recommendation = LeadProductMatch::query()
            ->whereKey($recommendationId)
            ->where('lead_id', $lead->id)
            ->with('product')
            ->first();

        if ($recommendation === null) {
            return 'That recommendation does not belong to this lead.';
        }

        // Section 5: the email follows the direction the user approved. A
        // suggestion nobody has accepted is not an approved direction.
        if ($recommendation->status !== RecommendationStatus::Accepted) {
            return 'Accept that product recommendation first. Emails are written from the sales '
                .'direction you approved, not from an unreviewed suggestion.';
        }

        if ($recommendation->product === null) {
            return 'The product behind that recommendation has been deleted.';
        }

        if (! $recommendation->product->active) {
            return 'That product is marked inactive. Reactivate it, or pick a different recommendation.';
        }

        return $recommendation;
    }

    /** The essential information, absent which no usable email can be written. */
    private function missingEssentials(Lead $lead): ?string
    {
        $lead->loadMissing('company');

        if (! $lead->isNamed()) {
            return 'This lead has no name yet. Add the person you want to write to first.';
        }

        if (blank($lead->email)) {
            return 'This lead has no email address. Add one before generating an email.';
        }

        if ($lead->company === null) {
            return 'This lead has no company. An outreach email needs one to be about.';
        }

        return null;
    }

    private function resolveReplacing(Lead $lead, int $generationId): ?EmailGeneration
    {
        if ($generationId <= 0) {
            return null;
        }

        return EmailGeneration::query()
            ->whereKey($generationId)
            ->where('lead_id', $lead->id)
            ->first();
    }
}
