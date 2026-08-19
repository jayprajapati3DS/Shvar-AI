<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyAnalysis;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Research\CompanyResearcher;
use App\Services\Research\Exceptions\ResearchException;
use Illuminate\Console\Command;

/**
 * Runs website research from the command line and prints what was found.
 *
 *     php artisan ai:research 3                     # an existing company by id
 *     php artisan ai:research --url=acme-medical.com --name="Acme Medical"
 *
 * A verification tool. It needs Ollama running and makes one real outbound
 * request to the site named. Findings are stored for review exactly as the UI
 * would store them - nothing is written to the company record.
 */
class ResearchCompanyCommand extends Command
{
    protected $signature = 'ai:research
                            {company? : Id of an existing company}
                            {--url= : Website to research instead}
                            {--name= : Company name, when using --url}
                            {--keep : Keep the temporary company created by --url}';

    protected $description = 'Research a company website with the local model and print the findings';

    public function handle(CompanyResearcher $researcher): int
    {
        if (! $researcher->enabled()) {
            $this->error('Website research is disabled. Set RESEARCH_FETCH_ENABLED=true in .env.');

            return self::FAILURE;
        }

        [$company, $temporary] = $this->resolveCompany();

        if ($company === null) {
            return self::FAILURE;
        }

        $this->line("Researching <info>{$company->name}</info> at <info>{$company->website}</info>");
        $this->line('This makes one outbound request, then reads the page with the local model.');
        $this->newLine();

        try {
            $analysis = $researcher->research($company);
        } catch (ResearchException|AiException $e) {
            $this->error('  '.$e->userMessage());

            if ($e->hint() !== null) {
                $this->line('  '.$e->hint());
            }

            $this->cleanUp($company, $temporary);

            return self::FAILURE;
        }

        $this->report($analysis);
        $this->cleanUp($company, $temporary);

        return self::SUCCESS;
    }

    /** @return array{0: Company|null, 1: bool} */
    private function resolveCompany(): array
    {
        $id = $this->argument('company');

        if ($id !== null) {
            $company = Company::find((int) $id);

            if ($company === null) {
                $this->error("No company with id {$id}.");

                return [null, false];
            }

            if (blank($company->website)) {
                $this->error("{$company->name} has no website recorded.");

                return [null, false];
            }

            return [$company, false];
        }

        $url = (string) $this->option('url');

        if ($url === '') {
            $this->error('Pass a company id, or --url=example.com.');

            return [null, false];
        }

        // A throwaway record so the normal code path can run unchanged.
        $company = Company::create([
            'name' => (string) ($this->option('name') ?: parse_url($url, PHP_URL_HOST) ?: $url),
            'website' => $url,
        ]);

        return [$company, ! $this->option('keep')];
    }

    private function report(CompanyAnalysis $analysis): void
    {
        $this->info(sprintf(
            'Read %d page(s) in %.1fs, model took %.1fs',
            count((array) $analysis->fetched_urls),
            ((int) $analysis->fetch_time_ms) / 1000,
            ((int) $analysis->execution_time_ms) / 1000,
        ));

        foreach ((array) $analysis->fetched_urls as $url) {
            $this->line("    {$url}");
        }

        $this->line(sprintf('    %s characters of page text sent to the model', number_format((int) $analysis->source_chars)));
        $this->newLine();

        $findings = (array) $analysis->findings;

        if ($findings === []) {
            $this->warn('  No findings could be verified against the page text.');
        }

        foreach ($findings as $finding) {
            $this->line(sprintf(
                '  <info>%-20s</info> %3d%%  %s',
                $finding['label'] ?? $finding['field'],
                (int) round(((float) ($finding['confidence'] ?? 0)) * 100),
                str_replace("\n", ' / ', mb_substr((string) $finding['value'], 0, 110)),
            ));
            $this->line(sprintf(
                '  %-20s       quote: %s',
                '',
                mb_substr((string) $finding['evidence'], 0, 100),
            ));
        }

        $notFound = array_column((array) $analysis->not_found, 'label');

        $this->newLine();
        $this->line('  not stated on the page: '.(implode(', ', $notFound ?: (array) $analysis->not_found) ?: 'nothing'));

        $warnings = (array) $analysis->warnings;

        if ($warnings !== []) {
            $this->newLine();
            $this->warn('  discarded by the validator:');

            foreach ($warnings as $warning) {
                $this->warn('    - '.$warning);
            }
        }
    }

    private function cleanUp(Company $company, bool $temporary): void
    {
        if (! $temporary) {
            return;
        }

        $company->delete();
        $this->newLine();
        $this->comment('  (temporary company removed; pass --keep to retain it)');
    }
}
