<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RecommendationStatus;
use App\Models\Lead;
use App\Services\AI\Exceptions\AiException;
use App\Services\Email\EmailGenerator;
use App\Services\Email\EmailRenderer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Generates real emails for the demo leads and prints them.
 *
 *     php artisan email:scenarios              # every eligible lead
 *     php artisan email:scenarios 3 5          # specific lead ids
 *
 * A verification tool, not part of the product. It needs Ollama running and
 * takes a few minutes per lead on CPU. The automated test suite covers the same
 * logic with mocked responses and needs neither.
 *
 * Pair it with the seeders:
 *
 *     php artisan db:seed --class=SampleDataSeeder
 *     php artisan db:seed --class=EmailScenarioSeeder
 *
 * Nothing here sends anything. Drafts are created exactly as the UI creates
 * them: unapproved, unsent, and waiting for a human.
 */
class RunEmailScenarios extends Command
{
    protected $signature = 'email:scenarios
                            {leads?* : Lead ids to write for. Defaults to every eligible lead.}';

    protected $description = 'Generate outreach emails for demo leads and print the results';

    public function handle(EmailGenerator $generator, EmailRenderer $renderer): int
    {
        $ids = array_map(intval(...), (array) $this->argument('leads'));

        $leads = Lead::query()
            ->with('company')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereHas('productMatches', fn ($q) => $q
                ->where('status', RecommendationStatus::Accepted->value))
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        if ($leads->isEmpty()) {
            $this->warn('No eligible leads. A lead needs an email address and an');
            $this->warn('accepted product recommendation. Try:');
            $this->line('  php artisan db:seed --class=EmailScenarioSeeder');

            return self::FAILURE;
        }

        $this->info("Writing emails for {$leads->count()} lead(s). This is slow on CPU.");
        $this->newLine();

        $failures = 0;

        foreach ($leads as $lead) {
            $recommendation = $lead->productMatches()
                ->where('status', RecommendationStatus::Accepted->value)
                ->with('product')
                ->first();

            $label = $lead->company?->name ?? "Lead #{$lead->id}";

            $this->line("<fg=cyan>── {$label}</> → {$recommendation->product?->name}");

            try {
                $generation = $generator->generate($lead, $recommendation);
            } catch (AiException $e) {
                $this->error('   '.$e->userMessage());
                $failures++;

                continue;
            } catch (Throwable $e) {
                $this->error('   '.$e->getMessage());
                $failures++;

                continue;
            }

            $this->line(sprintf(
                '   %d draft(s) in %ss via %s',
                $generation->drafts->count(),
                number_format($generation->seconds(), 1),
                $generation->model,
            ));

            foreach ($generation->drafts as $draft) {
                $this->newLine();
                $this->line("   <options=bold>[{$draft->variant->shortLabel()}]</> {$draft->subject}");

                foreach (explode("\n", $renderer->render($draft)['body']) as $line) {
                    $this->line('   '.$line);
                }
            }

            if ($generation->warnings !== []) {
                $this->newLine();
                $this->warn('   The output was filtered:');

                foreach ($generation->warnings as $warning) {
                    $this->warn('     - '.$warning);
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info('Done. Every draft is unapproved and unsent - review them in Email Drafts.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
