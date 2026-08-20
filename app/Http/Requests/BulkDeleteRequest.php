<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A selection of records to act on.
 *
 * Split out from BulkActionRequest because not everything that can be deleted in
 * bulk can be edited in bulk. Email drafts are the case in point: what a draft
 * is made of is a subject and a body written for one person, and there is no
 * field on it that means the same thing across forty of them. Offering a bulk
 * edit there would be offering a way to overwrite forty emails with the same
 * sentence.
 *
 * `ids` is capped. Not for safety - for honesty: a request large enough to time
 * out mid-way is worse than one that is refused up front.
 */
abstract class BulkDeleteRequest extends FormRequest
{
    /** Beyond this, use a filter and a smaller selection. */
    public const MAX_IDS = 500;

    /** The table `ids` must exist in. */
    abstract protected function table(): string;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer', Rule::exists($this->table(), 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one record first.',
            'ids.max' => 'Select at most '.self::MAX_IDS.' records at a time.',
            'ids.*.exists' => 'One of the selected records no longer exists. Refresh and try again.',
        ];
    }

    /**
     * The selected ids, de-duplicated.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_unique(array_map(
            intval(...),
            $this->validated('ids', []),
        )));
    }
}
