<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CompanyAnalysisResource;
use App\Models\Company;
use App\Models\CompanyAnalysis;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Research\CompanyResearchSchema;
use App\Services\AI\Research\CompanyResearcher;
use App\Services\Research\Exceptions\ResearchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Website research on the company page.
 *
 * The controller stays thin: validate, delegate, turn a typed exception into a
 * friendly message. It knows nothing about fetching, HTML or prompts.
 *
 * Findings never reach the company record on their own - apply() is the only
 * writer, and it runs only on the fields the user ticks.
 */
class CompanyResearchController extends Controller
{
    public function __construct(
        private readonly CompanyResearcher $researcher,
    ) {}

    /**
     * Fetch the company's website and extract what it says.
     *
     * Additive: each run stores a new analysis, so what the site said before a
     * redesign stays readable.
     */
    public function store(Request $request, Company $company): RedirectResponse
    {
        if (! $this->researcher->enabled()) {
            return back()->with(
                'error',
                'Website research is switched off. Set RESEARCH_FETCH_ENABLED=true in .env to enable it.',
            );
        }

        $validated = $request->validate([
            // Optional override, for when the stored website is wrong or the
            // useful text lives on a specific page.
            'url' => ['nullable', 'string', 'max:2048'],
        ]);

        $url = $validated['url'] ?? $company->website;

        if (blank($url)) {
            return back()->withErrors([
                'url' => 'Add a website address for this company first - research reads the site rather than guessing.',
            ]);
        }

        try {
            $analysis = $this->researcher->research($company, $url);
        } catch (ResearchException|AiException $e) {
            // The AI attempt, if it got that far, is already in the local log.
            return back()->with('error', trim($e->userMessage().' '.($e->hint() ?? '')));
        }

        $found = count((array) $analysis->findings);

        if ($found === 0) {
            return back()->with(
                'info',
                'The page was read, but nothing on it could be verified as a company fact. '
                .'Sites built entirely in JavaScript often look empty to a simple fetch.',
            );
        }

        return back()->with('success', sprintf(
            'Read %s and found %d verified detail(s). Review them before they are saved.',
            $analysis->requested_url,
            $found,
        ));
    }

    /**
     * Apply chosen findings to the company record.
     *
     * The only path by which research changes company data.
     */
    public function apply(Request $request, Company $company, CompanyAnalysis $analysis): RedirectResponse
    {
        abort_unless($analysis->company_id === $company->id, 404);

        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(CompanyResearchSchema::FIELDS)],
        ]);

        $applied = $this->researcher->apply($analysis, $validated['fields']);

        if ($applied === []) {
            return back()->with('error', 'None of those fields were available in that analysis.');
        }

        return back()->with('success', sprintf(
            'Saved %d field(s) to %s: %s.',
            count($applied),
            $company->name,
            implode(', ', array_map(CompanyResearchSchema::label(...), $applied)),
        ));
    }

    /** Full detail of one past research run, for the history viewer. */
    public function show(Company $company, CompanyAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->company_id === $company->id, 404);

        return response()->json([
            'analysis' => (new CompanyAnalysisResource($analysis))->resolve(),
        ]);
    }

    /** Discard an analysis. History is kept by default, so this is explicit. */
    public function destroy(Company $company, CompanyAnalysis $analysis): RedirectResponse
    {
        abort_unless($analysis->company_id === $company->id, 404);

        $analysis->delete();

        return back()->with('success', 'Research run discarded.');
    }
}
