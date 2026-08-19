<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model an activity timeline.
 *
 * A polymorphic relation cannot carry a database foreign key, so deleting the
 * subject would otherwise leave its activity rows orphaned in the table. This
 * trait cleans them up on delete.
 */
trait HasActivities
{
    public static function bootHasActivities(): void
    {
        static::deleting(function (self $model): void {
            $model->activities()->delete();
        });
    }

    /** @return MorphMany<Activity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('occurred_at');
    }
}
