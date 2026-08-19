<?php

declare(strict_types=1);

namespace App\Services\AI\Recommendation;

use App\Models\Lead;

/**
 * Renders a lead as prompt context.
 *
 * Only stored data is emitted. Fields that are empty are printed as
 * "(not recorded)" rather than omitted, which matters more than it looks:
 * a model shown a field marked not-recorded reliably reports it under
 * missing_information, whereas a model shown nothing at all tends to fill the
 * gap with a plausible invention.
 *
 * Nothing is fetched from the internet. Website is passed as a stored string,
 * never visited.
 */
class LeadContextBuilder
{
    private const NOT_RECORDED = '(not recorded)';

    public function render(Lead $lead): string
    {
        $lead->loadMissing('company');

        $company = $lead->company;
        $contact = $lead;

        $lines = [];

        $lines[] = '=== COMPANY ===';
        $lines[] = 'Name: '.$this->value($company?->name);
        $lines[] = 'Website (stored text only, not visited): '.$this->value($company?->website);
        $lines[] = 'Country: '.$this->value($company?->country);
        $lines[] = 'State/Region: '.$this->value($company?->state);
        $lines[] = 'City: '.$this->value($company?->city);
        $lines[] = 'Industry: '.$this->value($company?->industry);
        $lines[] = 'Company type: '.$this->value($company?->company_type);
        $lines[] = 'Description: '.$this->value($company?->description);
        $lines[] = 'Specialties: '.$this->value($company?->specialties);
        $lines[] = 'Products/services they offer: '.$this->value($company?->products_services);
        $lines[] = 'Notes on the company: '.$this->value($company?->notes);

        $lines[] = '';
        $lines[] = '=== CONTACT ===';
        $lines[] = 'Name: '.$this->value($contact?->full_name);
        $lines[] = 'Job title: '.$this->value($contact?->job_title);
        $lines[] = 'Department: '.$this->value($contact?->department);
        $lines[] = 'Country: '.$this->value($contact?->country);
        $lines[] = 'Notes on the contact: '.$this->value($contact?->notes);

        $lines[] = '';
        $lines[] = '=== LEAD ===';
        $lines[] = 'Lead source: '.$this->value($lead->lead_source);
        $lines[] = 'Lead status: '.$lead->lead_status->value;
        $lines[] = 'Priority: '.$lead->priority->value;
        $lines[] = 'Notes on the lead: '.$this->value($lead->notes);

        return implode("\n", $lines);
    }

    /**
     * How much the record actually says, 0.0-1.0.
     *
     * Feeds ConfidenceCalibrator. The weighting is not arbitrary: description,
     * industry, company_type and products_services are what a recommendation can
     * genuinely be reasoned from, so they carry most of the weight. A lead with
     * only a company name should not support a 0.95 match no matter how
     * confident the model sounds.
     */
    public function evidenceStrength(Lead $lead): float
    {
        $lead->loadMissing('company');

        $company = $lead->company;
        $contact = $lead;

        $weighted = [
            // The substance a match can actually be argued from.
            3.0 => [$company?->description, $company?->products_services],
            2.0 => [$company?->industry, $company?->company_type, $company?->specialties],
            // Supporting signal.
            1.0 => [$contact?->job_title, $contact?->department, $company?->country, $lead->notes],
        ];

        $earned = 0.0;
        $possible = 0.0;

        foreach ($weighted as $weight => $fields) {
            foreach ($fields as $field) {
                $possible += (float) $weight;

                if (filled($field)) {
                    $earned += (float) $weight;
                }
            }
        }

        return $possible > 0.0 ? round($earned / $possible, 3) : 0.0;
    }

    /**
     * Fields a human could fill in to improve the next analysis.
     *
     * Computed rather than asked of the model, so the UI can show something
     * useful even when the AI omits missing_information entirely.
     *
     * @return list<string>
     */
    public function gaps(Lead $lead): array
    {
        $lead->loadMissing('company');

        $checks = [
            'Company description' => $lead->company?->description,
            'Industry' => $lead->company?->industry,
            'Company type' => $lead->company?->company_type,
            'What the company sells (products/services)' => $lead->company?->products_services,
            'Clinical specialties' => $lead->company?->specialties,
            'Contact job title' => $lead->job_title,
        ];

        return array_values(array_keys(array_filter($checks, fn ($value) => blank($value))));
    }

    private function value(?string $value): string
    {
        return filled($value) ? trim($value) : self::NOT_RECORDED;
    }
}
