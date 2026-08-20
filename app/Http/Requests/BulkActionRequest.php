<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Base for the bulk update endpoints.
 *
 * Adds editing to the selection BulkDeleteRequest already validates. The rules
 * come from the model's own bulkEditableFields(), so a field cannot be written
 * by a bulk request unless the model declares it. That is the whole point: the
 * payload is a map of arbitrary column names, and without a whitelist derived
 * from a single source of truth this endpoint would be a mass-assignment hole.
 */
abstract class BulkActionRequest extends BulkDeleteRequest
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $model = $this->model();

        return [
            ...parent::rules(),

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
            ...parent::messages(),
            'clear.*.in' => 'That field cannot be emptied.',
        ];
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
