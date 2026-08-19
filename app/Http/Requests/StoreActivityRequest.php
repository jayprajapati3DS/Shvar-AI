<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ActivityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Manual types only. Email / Follow-up / Status Change are written
            // by the system, not through this form.
            'type' => ['required', Rule::in(array_map(
                fn (ActivityType $t) => $t->value,
                ActivityType::manualCases()
            ))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
