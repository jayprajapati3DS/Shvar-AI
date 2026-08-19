<?php

declare(strict_types=1);

namespace App\Services\AI\Research;

use App\Enums\AiRequestType;
use App\Models\Company;
use App\Models\CompanyAnalysis;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\PromptLibrary;
use App\Services\Research\Exceptions\FetchFailedException;
use App\Services\Research\Exceptions\UnsafeUrlException;
use App\Services\Research\HtmlTextExtractor;
use App\Services\Research\WebsiteFetcher;

/**
 * Researches a company by reading its website.
 *
 *   company name + website  (typed by the user)
 *        v
 *   WebsiteFetcher      <- the ONE outbound request; PublicUrlGuard checks it
 *        v
 *   HtmlTextExtractor   <- HTML to readable text
 *        v
 *   PromptLibrary::companyResearch()
 *        v
 *   AIServiceInterface::generateStructured()   <- local model, localhost
 *        v
 *   CompanyResearchValidator   <- verifies every quote is really on the page
 *        v
 *   company_analyses   (findings await review; the company is NOT modified)
 *
 * The model never recalls anything here - it reads text we hand it. That is the
 * whole design: recall is where a small model invents a company wholesale,
 * extraction is something it does adequately and checkably.
 *
 * Nothing is written to the company record. Applying a finding is a separate,
 * explicit action.
 */
class CompanyResearcher
{
    public function __construct(
        private readonly WebsiteFetcher $fetcher,
        private readonly HtmlTextExtractor $extractor,
        private readonly AIServiceInterface $ai,
        private readonly PromptLibrary $prompts,
        private readonly CompanyResearchSchema $schema,
        private readonly CompanyResearchValidator $validator,
    ) {}

    public function enabled(): bool
    {
        return $this->fetcher->enabled();
    }

    /**
     * Fetch the site, read it, and store the findings for review.
     *
     * @throws FetchFailedException|UnsafeUrlException
     * @throws AiException
     */
    public function research(Company $company, ?string $url = null): CompanyAnalysis
    {
        $requested = $url ?? (string) $company->website;

        $fetchStarted = hrtime(true);

        // Any fetch failure propagates: there is nothing to analyse without a
        // page, and inventing one is precisely what this feature exists to avoid.
        $pages = $this->fetcher->fetchSite($requested);

        $extracted = array_map(
            fn (array $page) => $this->extractor->extract($page),
            $pages,
        );

        $sourceText = $this->extractor->render($extracted);
        $fetchMs = (int) ((hrtime(true) - $fetchStarted) / 1_000_000);

        $result = $this->ai->generateStructured(
            $this->prompts->companyResearch($company->name, $requested, $sourceText),
            $this->schema->schema(),
            AiRequestType::CompanyAnalysis,
            ['company_id' => $company->id],
        );

        // The validator is given the SAME text the model saw, so a quote can be
        // checked against exactly what was available to copy from.
        $validated = $this->validator->validate($result->data ?? [], $sourceText);

        return CompanyAnalysis::create([
            'company_id' => $company->id,
            'requested_url' => $requested,
            'fetched_urls' => array_column($extracted, 'url'),
            'provider' => $result->provider,
            'model' => $result->model,
            'findings' => $validated['findings'],
            'not_found' => $validated['not_found'],
            'warnings' => $validated['warnings'],
            'page_summary' => $validated['page_summary'],
            'fetch_time_ms' => $fetchMs,
            'execution_time_ms' => $result->executionMs,
            'source_chars' => mb_strlen($sourceText),
            'ai_request_id' => $result->logId,
        ])->load('company');
    }

    /**
     * Apply chosen findings to the company record.
     *
     * The only path by which research changes company data, and it runs only
     * when the user asks. Fields not listed are left exactly as they were.
     *
     * @param  list<string>  $fields
     * @return list<string> the fields actually written
     */
    public function apply(CompanyAnalysis $analysis, array $fields): array
    {
        $company = $analysis->company;
        $applied = [];
        $attributes = [];

        foreach ($fields as $field) {
            if (! in_array($field, CompanyResearchSchema::FIELDS, true)) {
                continue;
            }

            $finding = $analysis->finding($field);

            if ($finding === null) {
                continue;
            }

            $attributes[$field] = $finding['value'];
            $applied[] = $field;
        }

        if ($attributes !== []) {
            $company->update($attributes);
        }

        $analysis->update([
            // Union with anything applied earlier, so a second pass over the
            // same analysis does not forget the first.
            'applied_fields' => array_values(array_unique([
                ...(array) $analysis->applied_fields,
                ...$applied,
            ])),
            'reviewed_at' => now(),
        ]);

        return $applied;
    }
}
