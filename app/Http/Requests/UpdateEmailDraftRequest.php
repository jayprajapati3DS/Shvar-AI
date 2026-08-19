<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:'.config('email.limits.max_subject_chars', 150)],
            'body' => ['required', 'string', 'max:'.config('email.limits.max_body_chars', 20000)],
        ];
    }

    /**
     * Strip markup before validation.
     *
     * The AI's output is sanitised by EmailValidator, but this text has been
     * through a human and a browser since. Plain text is the only format Phase 4
     * produces, so anything that could render or execute is removed at the edge
     * rather than trusted to be escaped correctly by every consumer downstream.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => $this->plain((string) $this->input('subject', '')),
            'body' => $this->plain((string) $this->input('body', '')),
        ]);
    }

    private function plain(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Decoding can reveal a tag that arrived entity-encoded.
        return trim(strip_tags($value));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subject.required' => 'An email needs a subject line.',
            'body.required' => 'An email needs a body.',
        ];
    }
}
