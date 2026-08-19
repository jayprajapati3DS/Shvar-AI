<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\AI\AiSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Settings -> AI form.
 *
 * SECURITY: there is deliberately no rule for `url`, `endpoint` or `provider`.
 * Those are server configuration, and AiSettings::save() ignores any key outside
 * WRITABLE_KEYS - so even a hand-crafted request cannot repoint AI traffic at a
 * remote host through this endpoint.
 */
class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Single-user local application: no auth layer.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'model' => ['required', 'string', 'max:200'],
            'temperature' => [
                'required', 'numeric',
                'min:'.config('ai.limits.min_temperature', 0),
                'max:'.config('ai.limits.max_temperature', 2),
            ],
            'timeout' => [
                'required', 'integer',
                'min:'.config('ai.limits.min_timeout', 5),
                'max:'.config('ai.limits.max_timeout', 600),
            ],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:32768'],
            'system_prompt' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'model.required' => 'Choose a model. Install one with "ollama pull <model>" if the list is empty.',
            'timeout.min' => 'A timeout below :min seconds is not enough for a local model to load.',
        ];
    }

    /** Only the keys AiSettings will actually persist. */
    public function settings(): array
    {
        return $this->safe()->only(AiSettings::WRITABLE_KEYS);
    }
}
