<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiJobType;
use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Resources\CompanyAnalysisResource;
use App\Models\Company;
use App\Models\CompanyAnalysis;
use App\Services\AI\Jobs\AiJobQueue;
use App\Services\AI\Research\CompanyResearcher;
use App\Services\AI\Research\CompanyResearchSchema;
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
    use RedirectsToOrigin;

    public function __construct(
        private readonly CompanyResearcher $researcher,
        private readonly AiJobQueue $queue,
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
            return $this->backTo('companies.show', $company->id)->with(
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
            return $this->backTo('companies.show', $company->id)->withErrors([
                'url' => 'Add a website address for this company first - research reads the site rather than guessing.',
            ]);
        }

        if ($this->queue->activeFor(AiJobType::CompanyResearch, $company) !== null) {
            return $this->backTo('companies.show', $company->id)->with(
                'info',
                'Research on this company is already running. Watch it in the AI activity tray.',
            );
        }

        $this->queue->push(
            AiJobType::CompanyResearch,
            $company,
            sprintf('Researching %s', $company->name),
            route('companies.show', $company),
            ['url' => $url],
        );

        return $this->backTo('companies.show', $company->id)->with(
            'info',
            'Research queued. The page is fetched and read by the local model in the background - '
            .'carry on working, the AI activity tray will tell you when it is done.',
        );
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
            return $this->backTo('companies.show', $company->id)->with('error', 'None of those fields were available in that analysis.');
        }

        return $this->backTo('companies.show', $company->id)->with('success', sprintf(
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

        return $this->backTo('companies.show', $company->id)->with('success', 'Research run discarded.');
    }
}
