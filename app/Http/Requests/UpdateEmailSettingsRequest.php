<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EmailLength;
use App\Enums\EmailTone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sender_name' => ['nullable', 'string', 'max:150'],
            'sender_job_title' => ['nullable', 'string', 'max:150'],
            'sender_company' => ['nullable', 'string', 'max:150'],

            // Validated as an address because it is offered as the From line in
            // the preview, and a malformed one there would be misleading.
            'sender_email' => ['nullable', 'email', 'max:200'],

            'sender_phone' => ['nullable', 'string', 'max:60'],
            'sender_website' => ['nullable', 'string', 'max:200'],
            'sender_linkedin' => ['nullable', 'string', 'max:200'],

            'signature' => ['nullable', 'string', 'max:1000'],

            'tone' => ['nullable', Rule::enum(EmailTone::class)],
            'length' => ['nullable', Rule::enum(EmailLength::class)],

            // Whether generations learn from the emails you approve.
            'learning_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Strip markup from every field.
     *
     * These values are appended verbatim to outgoing plain-text email, so
     * markup here would either render somewhere unintended or be sent as
     * literal noise. Neither is wanted.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (array_keys($this->rules()) as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            $clean[$key] = is_string($value) ? trim(strip_tags($value)) : $value;
        }

        $this->merge($clean);
    }
}
