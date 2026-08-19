<?php

declare(strict_types=1);

namespace App\Services\AI\Recommendation;

use App\Enums\RecommendationPriority;

/**
 * The JSON Schema the model is constrained to.
 *
 * Passed to Ollama's `format` parameter, which constrains generation rather
 * than merely requesting a shape. That is the first line of defence; the second
 * is RecommendationValidator, because a constrained model can still emit a
 * structurally valid object full of nonsense - an invented product_id, a
 * confidence of 7, an empty reason.
 *
 * `required` is kept deliberately short. Demanding every field from a small
 * local model produces refusals and empty completions; the validator supplies
 * sane defaults for anything optional that is missing.
 */
class RecommendationSchema
{
    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'company_summary' => ['type' => 'string'],
                'company_type' => ['type' => 'string'],
                'business_opportunity' => ['type' => 'string'],

                'recommended_products' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer'],
                            'product_name' => ['type' => 'string'],
                            'priority' => [
                                'type' => 'string',
                                'enum' => RecommendationPriority::values(),
                            ],
                            'confidence_score' => [
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 1,
                            ],
                            'reason' => ['type' => 'string'],
                            'evidence' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'sales_angle' => ['type' => 'string'],
                            'suggested_use_case' => ['type' => 'string'],
                            // A capability inside the product, or null.
                            'module' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['product_id', 'priority', 'confidence_score', 'reason'],
                    ],
                ],

                'primary_recommendation' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'product_name' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'sales_angle' => ['type' => 'string'],
                    ],
                ],

                'products_to_avoid' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_name' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['product_name', 'reason'],
                    ],
                ],

                'missing_information' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],

                'recommended_next_action' => ['type' => 'string'],
            ],

            // Short on purpose - see the class docblock. recommended_products is
            // required but may legitimately be an empty array, which is how the
            // model says "nothing in this portfolio fits".
            'required' => ['company_summary', 'business_opportunity', 'recommended_products'],
        ];
    }
}
