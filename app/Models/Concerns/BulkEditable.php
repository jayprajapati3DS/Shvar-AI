<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Declares which fields a model exposes to bulk editing.
 *
 * One definition drives three things: the validation rules, the fields the
 * modal renders, and the whitelist BulkEditor applies. Keeping them together
 * means a field cannot appear in the UI without also being validated, which is
 * exactly the mistake that turns a bulk edit into a mass-assignment hole.
 *
 * Only fields that are *sensible to set identically across many records* belong
 * here. A name, an email or a description is per-record by nature and is
 * deliberately absent.
 */
trait BulkEditable
{
    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     type: 'select'|'text'|'boolean',
     *     rules: list<mixed>,
     *     options?: list<array{value: string|int, label: string}>,
     *     nullable?: bool,
     *     hint?: string
     * }>
     */
    abstract public static function bulkEditableFields(): array;

    /**
     * The same definitions, safe to hand to the browser.
     *
     * `rules` is stripped: it can hold rule objects that do not serialise
     * meaningfully, and the client has no business seeing the server's
     * validation anyway - it is re-checked on every request regardless.
     *
     * @param  array<string, list<array{value: string|int, label: string}>>  $options
     *                                                                                 Runtime option lists, keyed by field, for fields whose choices are
     *                                                                                 data rather than an enum (a company list, say).
     * @return list<array<string, mixed>>
     */
    public static function bulkFieldsForUi(array $options = []): array
    {
        return array_map(function (array $field) use ($options): array {
            unset($field['rules']);

            if (isset($options[$field['key']])) {
                $field['options'] = $options[$field['key']];
            }

            $field['nullable'] = (bool) ($field['nullable'] ?? false);

            return $field;
        }, static::bulkEditableFields());
    }

    /**
     * Validation rules for a bulk edit, derived from the same definitions.
     *
     * Every field is `nullable` here because a bulk edit sends only what the
     * user chose to change; absence means "leave alone" and is not an error.
     *
     * @return array<string, mixed>
     */
    public static function bulkValidationRules(): array
    {
        $rules = [];

        foreach (static::bulkEditableFields() as $field) {
            $rules["values.{$field['key']}"] = ['nullable', ...($field['rules'] ?? [])];
        }

        return $rules;
    }

    /**
     * Fields that may appear in the `clear` list.
     *
     * @return list<string>
     */
    public static function bulkClearableKeys(): array
    {
        return array_values(array_map(
            fn (array $field) => $field['key'],
            array_filter(
                static::bulkEditableFields(),
                fn (array $field) => (bool) ($field['nullable'] ?? false),
            ),
        ));
    }

    /** @return list<string> */
    public static function bulkEditableKeys(): array
    {
        return array_column(static::bulkEditableFields(), 'key');
    }

    /** @return array<string, array<string, mixed>> keyed by field name */
    public static function bulkFieldMap(): array
    {
        $map = [];

        foreach (static::bulkEditableFields() as $field) {
            $map[$field['key']] = $field;
        }

        return $map;
    }

    /** Whether a field accepts being cleared rather than only set. */
    public static function bulkFieldIsNullable(string $key): bool
    {
        return (bool) (static::bulkFieldMap()[$key]['nullable'] ?? false);
    }
}
