<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailVariant;

/**
 * The JSON Schema the model is constrained to when writing emails.
 *
 * Passed to Ollama's `format` parameter, which constrains generation rather
 * than merely requesting a shape. That is the first line of defence; the second
 * is EmailValidator, because a constrained model still happily emits a
 * structurally perfect object containing "[Company]" placeholders and a claim
 * the product record never made.
 *
 * `required` is kept short on purpose. Demanding every field from a small local
 * model produces refusals and empty completions; the validator fills in what is
 * safe to default and rejects what is not.
 */
class EmailSchema
{
    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'variants' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'style' => [
                                'type' => 'string',
                                'enum' => EmailVariant::values(),
                            ],
                            'subject' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                        ],
                        'required' => ['style', 'subject', 'body'],
                    ],
                ],

                // The model's own account of what it personalised on. Checked
                // against the supplied context, not taken on trust.
                'personalization_points' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],

                // Every factual statement it made about the product. This is
                // the mechanism behind section 27: a claim that is not
                // traceable to the product record is a fabrication, and the
                // model has to enumerate them for that to be checkable.
                'claims_used' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],

                'missing_information' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],

            // variants, obviously - an email with no subject or body is not a
            // partial result, it is a failure.
            //
            // claims_used is required too, and that is a deliberate exception to
            // the "keep required short" rule. It is the entire mechanism behind
            // unsupported-claim detection: a claim the model does not enumerate
            // cannot be checked against the product record. Observed on
            // qwen3:4b, which returned three good emails and omitted the key
            // entirely, silently disabling the check.
            //
            // missing_information stays optional - it is useful, but nothing
            // depends on it, and EmailGenerator computes the gaps itself when
            // the model says nothing.
            'required' => ['variants', 'claims_used', 'personalization_points'],
        ];
    }
}
