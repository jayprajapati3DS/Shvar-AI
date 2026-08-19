<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Models\AiJob;
use App\Models\Lead;
use App\Services\AI\Recommendation\AiProductMatcher;

/**
 * Sales analysis for one lead, off the request thread.
 *
 * The longest of the four - four to five minutes on CPU - and the one that most
 * obviously did not belong in a browser request.
 *
 * Note what did not change in moving it here: every recommendation still lands
 * as Suggested, and accepting one is still something only a person does on the
 * lead page. Running unattended does not make the model's output any more
 * authoritative than it was when you watched it appear.
 */
class RunLeadAnalysis extends TrackedAiJob
{
    protected function run(AiJob $job): array
    {
        $lead = Lead::find($job->subject_id);

        if ($lead === null) {
            // Deleted between queueing and running. Worth saying plainly rather
            // than failing with a null reference.
            return [
                'summary' => 'That lead was deleted before the analysis ran.',
                'level' => 'info',
            ];
        }

        $analysis = app(AiProductMatcher::class)->analyse($lead);
        $count = $analysis->recommendations()->count();

        // An empty result is a legitimate outcome, not a failure - the model
        // deciding this company is outside the market. Same wording the
        // synchronous version used, for the same reason.
        if ($count === 0) {
            return [
                'summary' => 'No product in the portfolio was supported by the stored information.',
                'level' => 'info',
            ];
        }

        return [
            'summary' => sprintf(
                '%d recommendation(s) from %s in %ss. Review and accept the ones you want.',
                $count,
                $analysis->model,
                number_format((float) $analysis->seconds(), 1),
            ),
        ];
    }
}
