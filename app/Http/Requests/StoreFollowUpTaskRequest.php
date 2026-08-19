<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FollowUpStatus;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lead_id' => ['required', 'integer', 'exists:leads,id'],

            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'priority' => ['nullable', Rule::enum(Priority::class)],

            // Only on update - a new task is always Open.
            'status' => ['sometimes', Rule::enum(FollowUpStatus::class)],

            // No `after:today` rule. Backdating a reminder is a legitimate way
            // to say "this was already due", and refusing it would be the
            // application arguing with the user about their own to-do list.
            'due_on' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'What is the follow-up? A date with no action is not a reminder.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'notes'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $this->merge([$key => trim(strip_tags((string) $this->input($key)))]);
            }
        }
    }
}
