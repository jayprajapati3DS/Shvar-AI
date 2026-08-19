<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Email\SmtpSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSmtpSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Hostname only. A URL here would suggest the app fetches something,
            // which it does not - this is an SMTP endpoint, not an HTTP one.
            'smtp_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', Rule::in(SmtpSettings::ENCRYPTION_MODES)],
            'smtp_username' => ['nullable', 'string', 'max:255'],

            // May be the literal UNCHANGED sentinel: the form never receives the
            // real password, so it cannot submit it back, and treating a blank
            // as "clear it" would wipe the credential on every unrelated save.
            'smtp_password' => ['nullable', 'string', 'max:500'],

            'smtp_from_address' => ['nullable', 'email', 'max:255'],
            'smtp_from_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'smtp_host.regex' => 'Enter a hostname such as smtp.office365.com, not a URL.',
            'smtp_from_address.email' => 'The From address must be a valid email address.',
        ];
    }

    /**
     * Trim everything except the password, which is taken verbatim.
     *
     * A password may legitimately begin or end with a space, and silently
     * trimming one produces an authentication failure nobody can explain.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (array_keys($this->rules()) as $key) {
            if (! $this->has($key) || $key === 'smtp_password') {
                continue;
            }

            $value = $this->input($key);

            $clean[$key] = is_string($value) ? trim(strip_tags($value)) : $value;
        }

        $this->merge($clean);
    }
}
