<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Models\Lead;
use App\Models\LeadProductMatch;
use Illuminate\Support\Collection;

/**
 * Suggests which products fit a given lead.
 *
 * PHASE 1: not implemented. Product matches were created by hand on the lead
 * detail page and stored with recommendation_type = Manual.
 *
 * PHASE 3: implemented by App\Services\AI\Recommendation\AiProductMatcher,
 * which reads the company profile plus the product catalogue, asks the local
 * model, validates the result, and writes LeadProductMatch rows with an AI
 * recommendation_type, a calibrated confidence_score, a written reason and
 * supporting evidence.
 *
 * Manual selection remains available and unaffected - an AI recommendation never
 * replaces or blocks a hand-picked product.
 *
 * @see \App\Models\LeadProductMatch
 * @see \App\Enums\RecommendationType
 */
interface ProductMatcher
{
    /**
     * Score the product catalogue against a lead and persist the matches.
     *
     * @return Collection<int, LeadProductMatch>
     */
    public function match(Lead $lead): Collection;
}
