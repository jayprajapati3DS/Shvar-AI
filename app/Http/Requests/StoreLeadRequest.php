<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A lead is a person at a company, so this form carries both.
 *
 * The old version validated a contact_id and fussed about whether that contact
 * belonged to the same company. None of that exists any more: the person's
 * details are on the lead itself, so there is nothing to cross-check and
 * nothing to get out of step.
 */
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

            // The person.
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:60'],
            'linkedin_url' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],

            // The pursuit. Free text on source, not Rule::enum - LeadSource only
            // seeds the dropdown so an ad-hoc source can be typed without a code
            // change.
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
            // A lead needs SOMETHING to identify it. A row with no company, no
            // name and no address is not a lead, it is an empty record that
            // will sit in the list forever confusing everyone.
            $hasIdentity = filled($this->input('company_id'))
                || filled($this->input('first_name'))
                || filled($this->input('last_name'))
                || filled($this->input('email'));

            if (! $hasIdentity) {
                $validator->errors()->add(
                    'company_id',
                    'Give this lead a company, a name or an email address - otherwise there is '
                    .'nothing to identify it by.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.email' => 'That does not look like an email address.',
        ];
    }
}
