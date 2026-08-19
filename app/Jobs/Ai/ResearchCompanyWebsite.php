<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Models\AiJob;
use App\Models\Company;
use App\Services\AI\Research\CompanyResearcher;

/**
 * Read a company's website and extract what it says, off the request thread.
 *
 * The one job here that reaches outside this machine, and only ever to the
 * address stored on the company record - the same single fetch the synchronous
 * version made, subject to the same RESEARCH_FETCH_ENABLED switch. Running in a
 * worker changes when it happens, not what it is allowed to reach.
 *
 * Findings still never touch the company record on their own. The user ticks
 * the fields they want on the company page afterwards.
 */
class ResearchCompanyWebsite extends TrackedAiJob
{
    protected function run(AiJob $job): array
    {
        $company = Company::find($job->subject_id);

        if ($company === null) {
            return [
                'summary' => 'That company was deleted before the research ran.',
                'level' => 'info',
            ];
        }

        $url = $job->payload['url'] ?? $company->website;

        $analysis = app(CompanyResearcher::class)->research($company, $url);
        $found = count((array) $analysis->findings);

        if ($found === 0) {
            return [
                'summary' => 'The page was read, but nothing on it could be verified as a company fact. '
                    .'Sites built entirely in JavaScript often look empty to a simple fetch.',
                'level' => 'info',
            ];
        }

        return [
            'summary' => sprintf(
                'Read %s and found %d verified detail(s). Review them before they are saved.',
                $analysis->requested_url,
                $found,
            ),
        ];
    }
}
