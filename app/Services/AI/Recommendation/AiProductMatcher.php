<?php

declare(strict_types=1);

namespace App\Services\AI\Recommendation;

use App\Contracts\Ai\ProductMatcher;
use App\Enums\AiRequestType;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\LeadProductMatch;
use App\Services\AI\AiResult;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\PromptLibrary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analyses a lead against the product portfolio using the local model.
 *
 *   Lead + Company (database)
 *        v
 *   LeadContextBuilder / ProductContextBuilder   <- database only, no network
 *        v
 *   PromptLibrary::productRecommendation()
 *        v
 *   AIServiceInterface::generateStructured()     <- Ollama on localhost
 *        v
 *   RecommendationValidator + ConfidenceCalibrator
 *        v
 *   lead_analyses + lead_product_matches
 *
 * This is the Phase 1 ProductMatcher contract finally implemented. It depends on
 * AIServiceInterface, so it has no idea Ollama exists.
 *
 * Two rules it will not break:
 *
 *   1. Nothing is accepted on the user's behalf. Every recommendation is stored
 *      with status Suggested, and only an explicit user action changes that.
 *   2. Nothing from a previous analysis is modified or deleted. Each run inserts
 *      a fresh analysis and a fresh set of recommendations.
 */
class AiProductMatcher implements ProductMatcher
{
    public function __construct(
        private readonly AIServiceInterface $ai,
        private readonly PromptLibrary $prompts,
        private readonly LeadContextBuilder $leadContext,
        private readonly ProductContextBuilder $productContext,
        private readonly RecommendationSchema $schema,
        private readonly RecommendationValidator $validator,

        // What the user has corrected on past recommendations. Contributes
        // nothing until there are enough corrections to mean something.
        private readonly RecommendationFeedback $feedback,
    ) {}

    /**
     * ProductMatcher contract: score the catalogue and persist the matches.
     *
     * @return Collection<int, LeadProductMatch>
     */
    public function match(Lead $lead): Collection
    {
        return $this->analyse($lead)->recommendations;
    }

    /**
     * Run one analysis and persist it, returning the analysis with its
     * recommendations loaded.
     *
     * @throws AiException
     */
    public function analyse(Lead $lead): LeadAnalysis
    {
        $lead->loadMissing('company');

        $catalogue = $this->productContext->catalogue();
        $evidenceStrength = $this->leadContext->evidenceStrength($lead);

        $prompt = $this->prompts->productRecommendation(
            $this->leadContext->render($lead),
            $this->productContext->render($catalogue),
            $this->feedback->promptBlock(),
        );

        // Any AiException propagates to the controller, which turns it into a
        // friendly message. The attempt is already in the Phase 2 log by then.
        $result = $this->ai->generateStructured(
            $prompt,
            $this->schema->schema(),
            AiRequestType::ProductRecommendation,
            ['lead_id' => $lead->id],
        );

        $validated = $this->validator->validate(
            $result->data ?? [],
            $catalogue,
            $evidenceStrength,
        );

        return $this->persist($lead, $validated, $result, $evidenceStrength);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persist(
        Lead $lead,
        array $validated,
        AiResult $result,
        float $evidenceStrength,
    ): LeadAnalysis {
        return DB::transaction(function () use ($lead, $validated, $result): LeadAnalysis {
            // Anything the model got wrong is recorded alongside what it got
            // right, so the user can see the output was filtered.
            $missing = $validated['missing_information'];

            // Fall back to the computed gaps when the model says nothing useful.
            if ($missing === []) {
                $missing = $this->leadContext->gaps($lead);
            }

            $analysis = LeadAnalysis::create([
                'lead_id' => $lead->id,
                'provider' => $result->provider,
                'model' => $result->model,
                'company_summary' => $validated['company_summary'],
                'company_type' => $validated['company_type'],
                'business_opportunity' => $validated['business_opportunity'],
                'products_to_avoid' => $validated['products_to_avoid'],
                'missing_information' => $missing,
                'recommended_next_action' => $validated['recommended_next_action'],
                'warnings' => $validated['rejected'],
                'primary_product_id' => $validated['primary_product_id'],
                'primary_confidence' => $this->primaryConfidence($validated),
                'execution_time_ms' => $result->executionMs,
                'ai_request_id' => $result->logId,
            ]);

            foreach ($validated['recommendations'] as $recommendation) {
                LeadProductMatch::create([
                    'lead_id' => $lead->id,
                    'product_id' => $recommendation['product_id'],
                    'lead_analysis_id' => $analysis->id,

                    // Provenance: which one the AI led with.
                    'recommendation_type' => $recommendation['is_primary']
                        ? RecommendationType::AiPrimary
                        : RecommendationType::AiSecondary,

                    // Always Suggested. The AI never accepts its own work.
                    'status' => RecommendationStatus::Suggested,

                    'priority' => $recommendation['priority'],
                    'confidence_score' => $recommendation['confidence_score'],
                    'raw_confidence_score' => $recommendation['raw_confidence_score'],
                    'reason' => $recommendation['reason'],
                    'evidence' => $recommendation['evidence'],
                    'sales_angle' => $recommendation['sales_angle'],
                    'suggested_use_case' => $recommendation['suggested_use_case'],
                    'module' => $recommendation['module'],
                    'notes' => $recommendation['confidence_note'],
                ]);
            }

            return $analysis->load(['recommendations.product', 'primaryProduct']);
        });
    }

    /** @param array<string, mixed> $validated */
    private function primaryConfidence(array $validated): ?float
    {
        $primaryId = $validated['primary_product_id'];

        if ($primaryId === null) {
            return null;
        }

        foreach ($validated['recommendations'] as $recommendation) {
            if ($recommendation['product_id'] === $primaryId) {
                return $recommendation['confidence_score'];
            }
        }

        return null;
    }
}
