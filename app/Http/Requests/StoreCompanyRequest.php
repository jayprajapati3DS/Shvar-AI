<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Single-user local application: no auth layer in Phase 1.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->route('company')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('companies', 'name')->ignore($companyId),
            ],
            'website' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:150'],
            'company_type' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:20000'],
            'specialties' => ['nullable', 'string', 'max:20000'],
            'products_services' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'A company with this name already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Accept "acme.com" and store a usable URL.
        $website = trim((string) $this->input('website'));

        if ($website !== '' && ! preg_match('#^https?://#i', $website)) {
            $this->merge(['website' => 'https://'.$website]);
        }
    }
}
