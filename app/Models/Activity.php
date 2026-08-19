<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry on an activity timeline. Polymorphic so leads, companies and
 * contacts all share the same structure and the same Vue component.
 */
class Activity extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'type',
        'title',
        'body',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Convenience recorder used by controllers/services.
     */
    public static function record(
        Model $subject,
        ActivityType $type,
        string $title,
        ?string $body = null,
    ): self {
        return $subject->activities()->create([
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'occurred_at' => now(),
        ]);
    }
}
