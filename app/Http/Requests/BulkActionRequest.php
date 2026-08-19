<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base for the bulk update/delete endpoints.
 *
 * The rules come from the model's own bulkEditableFields(), so a field cannot
 * be written by a bulk request unless the model declares it. That is the whole
 * point: the payload is a map of arbitrary column names, and without a
 * whitelist derived from a single source of truth this endpoint would be a
 * mass-assignment hole.
 *
 * `ids` is capped. Not for safety - for honesty: a request large enough to time
 * out mid-way is worse than one that is refused up front.
 */
abstract class BulkActionRequest extends FormRequest
{
    /** Beyond this, use a filter and a smaller selection. */
    public const MAX_IDS = 500;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** The table `ids` must exist in. */
    abstract protected function table(): string;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $model = $this->model();

        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer', Rule::exists($this->table(), 'id')],

            'values' => ['sometimes', 'array'],
            ...$model::bulkValidationRules(),

            // Only nullable fields can be cleared. A `clear` naming a required
            // column is rejected outright rather than quietly dropped, so the
            // UI cannot drift out of step with the model without someone
            // noticing.
            'clear' => ['sometimes', 'array'],
            'clear.*' => [Rule::in($model::bulkClearableKeys())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one record first.',
            'ids.max' => 'Select at most '.self::MAX_IDS.' records at a time.',
            'ids.*.exists' => 'One of the selected records no longer exists. Refresh and try again.',
            'clear.*.in' => 'That field cannot be emptied.',
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

    /**
     * Fields the user chose to set.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->validated('values', []);
    }

    /**
     * Fields the user chose to empty.
     *
     * @return list<string>
     */
    public function clear(): array
    {
        return array_values(array_unique($this->validated('clear', [])));
    }

    /**
     * Guard the one combination the UI should never send: setting and clearing
     * the same field. Silently preferring one would make the result depend on
     * an ordering nobody can see.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $conflicts = array_intersect(
                array_keys(array_filter(
                    $this->values(),
                    fn ($value) => $value !== null && $value !== '',
                )),
                $this->clear(),
            );

            foreach ($conflicts as $key) {
                $validator->errors()->add("values.{$key}", 'Choose either a new value or "clear", not both.');
            }
        });
    }
}
