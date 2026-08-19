<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Enums\RecommendationStatus;
use App\Models\AiJob;
use App\Models\EmailGeneration;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Services\Email\EmailGenerator;

/**
 * Three email variants for one lead, off the request thread.
 *
 * The checks the controller made before queueing are made again here, because
 * time passes between the two: a recommendation can be rejected, a product
 * deactivated, or an email address cleared while the job waits its turn. The
 * rule that an email is written only from a direction the user approved has to
 * hold at the moment the writing happens, not at the moment it was requested.
 *
 * Nothing is sent. A finished job leaves drafts on the lead page awaiting
 * approval, exactly as before.
 */
class GenerateEmailDrafts extends TrackedAiJob
{
    protected function run(AiJob $job): array
    {
        $lead = Lead::with('company')->find($job->subject_id);

        if ($lead === null) {
            return [
                'summary' => 'That lead was deleted before the drafts were written.',
                'level' => 'info',
            ];
        }

        $payload = $job->payload ?? [];

        $recommendations = LeadProductMatch::query()
            ->whereIn('id', (array) ($payload['recommendation_ids'] ?? []))
            ->where('lead_id', $lead->id)
            ->where('status', RecommendationStatus::Accepted)
            ->with('product')
            ->get()
            // Order is meaningful - the model is told to lead with the first -
            // and whereIn does not preserve it.
            ->sortBy(fn (LeadProductMatch $m) => array_search(
                $m->id,
                (array) ($payload['recommendation_ids'] ?? []),
                strict: true,
            ))
            ->values();

        if ($recommendations->isEmpty()) {
            return [
                'summary' => 'The product recommendation this email was about is no longer accepted. '
                    .'Accept it again, then regenerate.',
                'level' => 'info',
            ];
        }

        $replacing = isset($payload['regenerate_from'])
            ? EmailGeneration::query()
                ->whereKey($payload['regenerate_from'])
                ->where('lead_id', $lead->id)
                ->first()
            : null;

        $generation = app(EmailGenerator::class)->generate(
            $lead,
            $recommendations,
            $payload['extra_instructions'] ?? null,
            $replacing,
        );

        $count = $generation->drafts()->count();
        $products = $generation->recommendations()->count();

        $summary = sprintf(
            '%d draft(s) about %s, written by %s in %ss. Nothing has been sent - review, edit and approve first.',
            $count,
            $products === 1
                ? ($recommendations->first()?->product?->name ?? 'the selected product')
                : $products.' products',
            $generation->model,
            number_format($generation->seconds(), 1),
        );

        if ($replacing !== null) {
            $summary .= ' Compare them against the previous version before choosing.';
        }

        return ['summary' => $summary];
    }
}
