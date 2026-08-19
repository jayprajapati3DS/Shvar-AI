<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Email\EmailGenerator;
use Illuminate\Foundation\Http\FormRequest;

class GenerateEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Ownership and accepted-status are checked in the controller,
            // which can explain what to do about it. This is just shape.
            //
            // recommendation_id is the PRIMARY - the product the email leads
            // with. It stays required and singular even for a multi-product
            // email, because "what is this mainly about" always has one answer.
            'recommendation_id' => ['required', 'integer', 'exists:lead_product_matches,id'],

            // Additional products to weave in, in priority order. Capped in the
            // controller against EmailGenerator::MAX_PRODUCTS.
            'secondary_recommendation_ids' => ['sometimes', 'array', 'max:'.(EmailGenerator::MAX_PRODUCTS - 1)],
            'secondary_recommendation_ids.*' => ['integer', 'exists:lead_product_matches,id'],

            // Free text steer for this run. Capped: it is concatenated into the
            // prompt, and an enormous instruction block would crowd out the
            // actual company and product data the email has to be built from.
            'extra_instructions' => ['nullable', 'string', 'max:1000'],

            'regenerate_from' => ['nullable', 'integer', 'exists:email_generations,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'recommendation_id.required' => 'Choose which accepted product recommendation to write about.',
            'secondary_recommendation_ids.max' => 'An email can pitch at most '
                .EmailGenerator::MAX_PRODUCTS.' products. Past that it reads as a catalogue.',
            'extra_instructions.max' => 'Keep additional instructions under 1000 characters.',
        ];
    }
}
