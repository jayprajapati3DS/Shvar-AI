<?php

declare(strict_types=1);

namespace App\Services\AI\Research;

/**
 * The shape the model must return when reading a company website.
 *
 * A flat list of findings rather than one object per field: the structure
 * repeats identically for every entry, which small models handle far more
 * reliably than a bespoke key for each field.
 *
 * Every finding must carry `evidence` - a quote from the page. That is not
 * decoration: CompanyResearchValidator checks the quote actually appears in the
 * fetched text and discards the finding if it does not. It is what turns
 * "the model said so" into "the page said so".
 */
class CompanyResearchSchema
{
    /**
     * Company fields the model is allowed to fill.
     *
     * Deliberately excludes `name` (the user typed it), `website` (likewise)
     * and `notes` (yours, not the model's).
     *
     * @var list<string>
     */
    public const FIELDS = [
        'industry',
        'company_type',
        'description',
        'specialties',
        'products_services',
        'country',
        'state',
        'city',
    ];

    /** Fields whose value is a list, stored newline-separated. */
    public const LIST_FIELDS = ['specialties', 'products_services'];

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string', 'enum' => self::FIELDS],
                            'value' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                        'required' => ['field', 'value', 'evidence'],
                    ],
                ],

                // What the page did not say. Reported rather than guessed.
                'not_found' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::FIELDS],
                ],

                // One sentence on what the site is actually about, used as a
                // sanity check that the right page was read.
                'page_summary' => ['type' => 'string'],
            ],
            'required' => ['findings'],
        ];
    }

    /** Human label for a field, for the review UI. */
    public static function label(string $field): string
    {
        return match ($field) {
            'industry' => 'Industry',
            'company_type' => 'Company type',
            'description' => 'Description',
            'specialties' => 'Specialties',
            'products_services' => 'Products / services',
            'country' => 'Country',
            'state' => 'State / region',
            'city' => 'City',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }
}
