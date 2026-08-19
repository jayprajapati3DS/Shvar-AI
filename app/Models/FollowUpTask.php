<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowUpStatus;
use App\Enums\Priority;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something to do about a lead, on a date.
 *
 * Suggested by the model after reading a reply, or written by hand. `source`
 * records which, and it is shown - knowing which items came from a machine is
 * the difference between a to-do list you trust and one you stop reading.
 *
 * Nothing here acts on its own. A task is a reminder; it never sends, schedules
 * or generates anything.
 */
class FollowUpTask extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    protected $fillable = [
        'lead_id',
        'contact_id',
        'email_reply_id',
        'title',
        'notes',
        'status',
        'priority',
        'source',
        'due_on',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FollowUpStatus::class,
            'priority' => Priority::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** The reply that prompted it, when there was one. */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(EmailReply::class, 'email_reply_id');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', FollowUpStatus::Open->value);
    }

    /** @param Builder<self> $query */
    public function scopeDueBy(Builder $query, DateTimeInterface $date): Builder
    {
        return $query->whereNotNull('due_on')->whereDate('due_on', '<=', $date);
    }

    /**
     * Soonest first, with undated tasks last.
     *
     * A task with no date is not urgent by default - sorting nulls first would
     * bury everything that actually has a deadline.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSoonestFirst(Builder $query): Builder
    {
        return $query->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('id');
    }

    /**
     * Applies the follow-ups list filters.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['source'] ?? null, fn (Builder $q, string $v) => $q->where('source', $v))
            ->when(
                ($filters['due'] ?? null) === 'overdue',
                fn (Builder $q) => $q->whereNotNull('due_on')->whereDate('due_on', '<', now()),
            )
            ->when(
                ($filters['due'] ?? null) === 'today',
                fn (Builder $q) => $q->whereDate('due_on', now()),
            );
    }

    public function isOverdue(): bool
    {
        return $this->status === FollowUpStatus::Open
            && $this->due_on !== null
            && $this->due_on->isPast()
            && ! $this->due_on->isToday();
    }

    public function isFromAi(): bool
    {
        return $this->source === self::SOURCE_AI;
    }
}
