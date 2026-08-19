<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Applies an edit or a delete to a set of selected records.
 *
 * Two rules it will not bend:
 *
 *   1. Only fields the model declares in bulkEditableFields() are ever written.
 *      The request is untrusted; a bulk endpoint that accepted arbitrary
 *      attributes would be a mass-assignment hole with a nice UI on top.
 *
 *   2. Only fields the user actually chose are touched. Omitting a field means
 *      "leave it alone", never "set it to empty" - the classic bulk-edit bug
 *      that silently wipes a column across a hundred rows.
 *
 * Clearing is possible, but only explicitly: the field must be listed in
 * `clear` AND be declared nullable.
 */
class BulkEditor
{
    /**
     * Update the given records.
     *
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $values  Fields the user chose to set.
     * @param  list<string>  $clear  Fields the user chose to empty.
     * @param  callable(Model, array<string, mixed>): void|null  $onEach
     *                                                                    Runs inside the transaction per record, for domain side effects
     *                                                                    such as logging a status change to the activity timeline.
     * @return int records actually changed
     */
    public function update(
        string $model,
        array $ids,
        array $values,
        array $clear = [],
        ?callable $onEach = null,
    ): int {
        $attributes = $this->resolveAttributes($model, $values, $clear);

        if ($attributes === [] || $ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($model, $ids, $attributes, $onEach): int {
            // Fetched rather than mass-updated so casts, model events and the
            // per-record callback all run. A bulk edit of a few hundred rows is
            // not worth losing that for.
            $records = $model::query()->whereIn('id', $ids)->get();

            foreach ($records as $record) {
                if ($onEach !== null) {
                    $onEach($record, $attributes);
                }

                $record->update($attributes);
            }

            return $records->count();
        });
    }

    /**
     * Delete the given records.
     *
     * Each is deleted individually so model events fire - which matters here:
     * HasActivities cleans up polymorphic timeline rows on delete, and a mass
     * query-builder delete would skip that and orphan them.
     *
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     * @return int records deleted
     */
    public function delete(string $model, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($model, $ids): int {
            $records = $model::query()->whereIn('id', $ids)->get();

            foreach ($records as $record) {
                $record->delete();
            }

            return $records->count();
        });
    }

    /**
     * Narrow the request down to what the model permits.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $values
     * @param  list<string>  $clear
     * @return array<string, mixed>
     */
    private function resolveAttributes(string $model, array $values, array $clear): array
    {
        /** @var list<string> $allowed */
        $allowed = $model::bulkEditableKeys();

        $attributes = [];

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            // An empty string here means the field was shown but left blank.
            // That is not an instruction to clear it - `clear` is.
            if ($value === null || $value === '') {
                continue;
            }

            $attributes[$key] = $value;
        }

        foreach ($clear as $key) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            // Guard the obvious footgun: emptying a column the model requires.
            if (! $model::bulkFieldIsNullable($key)) {
                continue;
            }

            $attributes[$key] = null;
        }

        return $attributes;
    }
}
