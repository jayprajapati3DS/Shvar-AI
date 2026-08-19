<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Lead;
use App\Models\LeadProductMatch;

/**
 * Renders everything the model is allowed to know about this email.
 *
 * Only stored data is emitted, and every field comes from a database row. There
 * is no fetch, no search, no external lookup - the model writes from what the
 * CRM holds or it does not write it at all. That is what makes the "never
 * pretend research was performed" rule enforceable rather than advisory.
 *
 * Empty fields are printed as "(not recorded)" rather than omitted, for the
 * same reason as LeadContextBuilder: a model shown a field marked not-recorded
 * reports it as missing, whereas a model shown nothing invents something
 * plausible to fill the gap.
 */
class EmailContextBuilder
{
    private const NOT_RECORDED = '(not recorded)';

    public function __construct(
        private readonly EmailSettings $settings,
    ) {}

    /**
     * The full prompt context for one email generation.
     *
     * @param  LeadProductMatch  $recommendation  The accepted Phase 3 recommendation
     *                                            this email is written from.
     */
    public function render(Lead $lead, LeadProductMatch $recommendation): string
    {
        $lead->loadMissing(['company', 'contact']);
        $recommendation->loadMissing(['product', 'analysis']);

        return implode("\n", [
            $this->senderBlock(),
            '',
            $this->contactBlock($lead),
            '',
            $this->companyBlock($lead),
            '',
            $this->leadBlock($lead),
            '',
            $this->productBlock($recommendation),
            '',
            $this->recommendationBlock($recommendation),
        ]);
    }

    /**
     * Who the email is FROM.
     *
     * This block exists because of a real failure. Without it the only company
     * name anywhere in the prompt was the recipient's, and the model wrote
     * "I am writing from Alpine Maxillofacial Solutions" - to the person who
     * works there. A model cannot infer which side of the conversation it is
     * on; it has to be told.
     */
    private function senderBlock(): string
    {
        return implode("\n", [
            '=== ME (the person sending this email) ===',
            'My name: '.$this->value($this->settings->senderName()),
            'My job title: '.$this->value($this->settings->senderJobTitle()),
            'The company I work for and am writing ON BEHALF OF: '
                .$this->value($this->settings->senderCompany()),
            '',
            'IMPORTANT: the company in the next section is the RECIPIENT\'s employer, not mine.',
            'I am approaching them from the outside. Never write as though I work there.',
        ]);
    }

    private function contactBlock(Lead $lead): string
    {
        $contact = $lead->contact;

        return implode("\n", [
            '=== RECIPIENT (the person this email is addressed to) ===',
            'First name: '.$this->value($contact?->first_name),
            'Last name: '.$this->value($contact?->last_name),
            'Job title: '.$this->value($contact?->job_title),
            'Department: '.$this->value($contact?->department),
            'Country: '.$this->value($contact?->country),
            'Notes on this person: '.$this->value($contact?->notes),
        ]);
    }

    private function companyBlock(Lead $lead): string
    {
        $company = $lead->company;

        return implode("\n", [
            '=== THEIR COMPANY ===',
            'Name: '.$this->value($company?->name),
            'Industry: '.$this->value($company?->industry),
            'Company type: '.$this->value($company?->company_type),
            'Country: '.$this->value($company?->country),
            'City: '.$this->value($company?->city),
            'What they do: '.$this->value($company?->description),
            'What they sell or provide: '.$this->value($company?->products_services),
            'Clinical or technical specialties: '.$this->value($company?->specialties),
            'Notes on the company: '.$this->value($company?->notes),
        ]);
    }

    private function leadBlock(Lead $lead): string
    {
        return implode("\n", [
            '=== HOW THIS LEAD AROSE ===',
            'Lead source: '.$this->value($lead->lead_source),
            'Lead status: '.$lead->lead_status->value,
            'Notes on the lead: '.$this->value($lead->notes),
        ]);
    }

    /**
     * The product block.
     *
     * This is the ONLY description of the product the model may use. Section 28
     * of the brief: the products table is the source of truth, and anything the
     * model "knows" about a product from pretraining is not evidence.
     */
    private function productBlock(LeadProductMatch $recommendation): string
    {
        $product = $recommendation->product;

        return implode("\n", [
            '=== THE PRODUCT I AM WRITING ABOUT ===',
            '(This is the complete and only description of this product you may use.',
            ' Do not add capabilities, customers, certifications or claims that are not written here.)',
            '',
            'Name: '.$this->value($product?->name),
            'Category: '.$this->value($product?->category),
            'Short description: '.$this->value($product?->short_description),
            'Detailed description: '.$this->value($product?->detailed_description),
            'Key features: '.$this->value($product?->key_features),
            'Who it is for: '.$this->value($product?->target_customer),
            'Specialties it serves: '.$this->value($product?->target_specialty),
            'Value proposition: '.$this->value($product?->value_proposition),
            'My private sales notes (do NOT quote these to the recipient): '
                .$this->value($product?->sales_notes),
        ]);
    }

    private function recommendationBlock(LeadProductMatch $recommendation): string
    {
        $evidence = $recommendation->evidence ?? [];

        $lines = [
            '=== THE APPROVED SALES DIRECTION (from my earlier analysis) ===',
            'Why this product is relevant to them: '.$this->value($recommendation->reason),
            'Sales angle to take: '.$this->value($recommendation->sales_angle),
            'Suggested use case: '.$this->value($recommendation->suggested_use_case),
            'Specific capability to lead with: '.$this->value($recommendation->module),
            'Confidence in this match: '.($recommendation->confidencePercent() !== null
                ? $recommendation->confidencePercent().'%'
                : self::NOT_RECORDED),
        ];

        if ($evidence !== []) {
            $lines[] = 'Evidence this match was based on:';

            foreach ($evidence as $item) {
                $lines[] = '  - '.$item;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Fields a human could fill in to improve the next email.
     *
     * Computed rather than asked of the model, so the UI can show something
     * useful even when the AI returns no missing_information at all.
     *
     * @return list<string>
     */
    public function gaps(Lead $lead, LeadProductMatch $recommendation): array
    {
        $lead->loadMissing(['company', 'contact']);
        $recommendation->loadMissing('product');

        $checks = [
            'Contact first name' => $lead->contact?->first_name,
            'Contact job title' => $lead->contact?->job_title,
            'Company description' => $lead->company?->description,
            'Industry' => $lead->company?->industry,
            'What the company sells' => $lead->company?->products_services,
            'Sales angle on the recommendation' => $recommendation->sales_angle,
            'Product value proposition' => $recommendation->product?->value_proposition,
        ];

        return array_values(array_keys(array_filter($checks, fn ($value) => blank($value))));
    }

    /**
     * Whether there is enough to write a genuinely personalised email.
     *
     * Distinct from the hard requirements the controller enforces: this is the
     * "additional information may improve personalization" nudge, not a block.
     */
    public function isThin(Lead $lead, LeadProductMatch $recommendation): bool
    {
        return count($this->gaps($lead, $recommendation)) >= 3;
    }

    private function value(?string $value): string
    {
        return filled($value) ? trim($value) : self::NOT_RECORDED;
    }
}
