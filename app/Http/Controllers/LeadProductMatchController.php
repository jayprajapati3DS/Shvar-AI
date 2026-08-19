<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadProductMatchRequest;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use Illuminate\Http\RedirectResponse;

/**
 * Manual product <-> lead association.
 *
 * Phase 1 is entirely manual: a human picks the product. The Phase 2 matcher
 * will write the same rows with an AI recommendation type and a confidence
 * score through App\Contracts\Ai\ProductMatcher.
 */
class LeadProductMatchController extends Controller
{
    public function store(StoreLeadProductMatchRequest $request, Lead $lead): RedirectResponse
    {
        $lead->productMatches()->create($request->matchAttributes());

        return back()->with('success', 'Product added to this lead.');
    }

    public function destroy(Lead $lead, LeadProductMatch $match): RedirectResponse
    {
        // Guard against a match id from another lead being passed in the URL.
        abort_unless($match->lead_id === $lead->id, 404);

        $match->delete();

        return back()->with('success', 'Product removed from this lead.');
    }
}
