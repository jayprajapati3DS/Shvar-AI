<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:180'],
            'department' => ['nullable', 'string', 'max:150'],
            // Not unique: the same person can legitimately appear twice while
            // you clean up imported data. Duplicate detection lives in the
            // CSV importer instead, where it can be reviewed before committing.
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'linkedin_url' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $linkedin = trim((string) $this->input('linkedin_url'));

        if ($linkedin !== '' && ! preg_match('#^https?://#i', $linkedin)) {
            $this->merge(['linkedin_url' => 'https://'.$linkedin]);
        }
    }
}
