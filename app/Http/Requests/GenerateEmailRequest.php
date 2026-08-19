<?php

declare(strict_types=1);

namespace App\Http\Requests;

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
            'recommendation_id' => ['required', 'integer', 'exists:lead_product_matches,id'],

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
            'extra_instructions.max' => 'Keep additional instructions under 1000 characters.',
        ];
    }
}
