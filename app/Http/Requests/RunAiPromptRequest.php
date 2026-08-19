<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an AI Playground submission.
 *
 * The prompt length is bounded here as well as in OllamaAIService: this gives
 * the user an inline field error instead of a thrown exception, while the service
 * check still protects any non-HTTP caller.
 */
class RunAiPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1', 'max:'.config('ai.limits.max_prompt_chars', 20000)],

            // Overriding the model per run is allowed; it must still be one of
            // the locally installed models, which the controller verifies.
            'model' => ['nullable', 'string', 'max:200'],

            'temperature' => [
                'nullable', 'numeric',
                'min:'.config('ai.limits.min_temperature', 0),
                'max:'.config('ai.limits.max_temperature', 2),
            ],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:32768'],

            // Ask for a JSON object back instead of prose.
            'structured' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Enter a prompt to run.',
            'prompt.max' => 'That prompt is too long. The limit is :max characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['structured' => $this->boolean('structured')]);
    }
}
