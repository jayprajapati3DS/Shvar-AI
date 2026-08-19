<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Recommendation\AiProductMatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the product-recommendation engine against real leads and prints a report.
 *
 *     php artisan ai:scenarios              # every lead
 *     php artisan ai:scenarios 3 5 7        # specific lead ids
 *
 * A verification tool, not part of the product. It needs Ollama running and
 * takes several minutes per lead on CPU. The automated test suite covers the
 * same logic with mocked responses and needs neither.
 *
 * Pair it with the sample data:
 *
 *     php artisan db:seed --class=RecommendationScenarioSeeder
 */
class RunRecommendationScenarios extends Command
{
    protected $signature = 'ai:scenarios
                            {leads?* : Lead ids to analyse. Defaults to all leads.}';

    protected $description = 'Run AI product recommendation against leads and print the results';

    public function handle(AiProductMatcher $matcher): int
    {
        $ids = array_map(intval(...), (array) $this->argument('leads'));

        $leads = Lead::with('company')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        if ($leads->isEmpty()) {
            $this->warn('No leads to analyse.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Analysing %d lead(s) with the local model. This takes several minutes each on CPU.',
            $leads->count(),
        ));

        $failures = 0;

        foreach ($leads as $lead) {
            $this->newLine();
            $this->line(str_repeat('=', 78));
            $this->info(sprintf(
                'Lead #%d  %s  [%s]',
                $lead->id,
                $lead->company?->name ?? 'no company',
                $lead->company?->company_type ?? 'no type',
            ));

            try {
                $analysis = $matcher->analyse($lead);
            } catch (AiException $e) {
                $failures++;
                $this->error('  '.$e->userMessage());

                continue;
            } catch (Throwable $e) {
                $failures++;
                $this->error('  Unexpected failure: '.$e->getMessage());

                continue;
            }

            $this->report($analysis);
        }

        $this->newLine();
        $this->line(str_repeat('=', 78));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function report(\App\Models\LeadAnalysis $analysis): void
    {
        $this->line(sprintf('  %s   %ss', $analysis->model, $analysis->seconds()));

        $this->line(sprintf(
            '  PRIMARY: %s (%d%%)',
            $analysis->primaryProduct?->name ?? 'NONE - nothing recommended',
            (int) round((float) $analysis->primary_confidence * 100),
        ));

        foreach ($analysis->recommendations as $recommendation) {
            $this->line(sprintf(
                '   - %-44s %-7s %3d%%%s',
                $recommendation->product?->name ?? '?',
                $recommendation->priority?->value ?? '-',
                $recommendation->confidencePercent() ?? 0,
                $recommendation->module ? '  ['.$recommendation->module.']' : '',
            ));
            $this->line('       '.mb_substr((string) $recommendation->reason, 0, 100));
        }

        if ($analysis->recommendations->isEmpty()) {
            $this->line('   (no products recommended - a valid outcome for a company outside the market)');
        }

        $this->line('  opportunity: '.mb_substr((string) $analysis->business_opportunity, 0, 140));
        $this->line('  next: '.mb_substr((string) $analysis->recommended_next_action, 0, 130));

        $warnings = (array) $analysis->warnings;

        if ($warnings !== []) {
            $this->warn('  filtered from the model output:');

            foreach ($warnings as $warning) {
                $this->warn('    - '.$warning);
            }
        }
    }
}
