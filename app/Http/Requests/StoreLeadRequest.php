<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Contact;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
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
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            // Free text, not Rule::enum - LeadSource only seeds the dropdown so
            // an ad-hoc source can be typed without a code change.
            'lead_source' => ['nullable', 'string', 'max:150'],
            'lead_status' => ['required', Rule::enum(LeadStatus::class)],
            'priority' => ['required', Rule::enum(Priority::class)],
            'assigned_to' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A lead with neither a company nor a contact is not actionable.
            if (blank($this->input('company_id')) && blank($this->input('contact_id'))) {
                $validator->errors()->add('company_id', 'Select a company or a contact for this lead.');
            }

            // Guard against attaching a contact that belongs elsewhere.
            $contactId = $this->input('contact_id');
            $companyId = $this->input('company_id');

            if (filled($contactId) && filled($companyId)) {
                $contactCompany = Contact::whereKey($contactId)->value('company_id');

                if ($contactCompany !== null && (int) $contactCompany !== (int) $companyId) {
                    $validator->errors()->add('contact_id', 'That contact belongs to a different company.');
                }
            }
        });
    }
}
