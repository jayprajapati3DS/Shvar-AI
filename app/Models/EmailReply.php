<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReplyClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message received in Outlook from someone in the CRM.
 *
 * Imported only when the sender address matches a Contact record - see
 * OutlookMailboxSync. Nothing else in the mailbox is read.
 *
 * outlook_entry_id is unique, which is what makes the sync idempotent: running
 * it twice imports nothing twice.
 */
class EmailReply extends Model
{
    protected $fillable = [
        'outlook_entry_id',
        'conversation_id',
        'contact_id',
        'lead_id',
        'email_draft_id',
        'from_address',
        'from_name',
        'subject',
        'body',
        'received_at',
        'classification',
        'summary',
        'sentiment',
        'signals',
        'ai_request_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'classification' => ReplyClassification::class,
            'signals' => 'array',
            'sentiment' => 'float',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** The draft this appears to answer, when one could be matched. */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(EmailDraft::class, 'email_draft_id');
    }

    /** @return HasMany<FollowUpTask, $this> */
    public function followUpTasks(): HasMany
    {
        return $this->hasMany(FollowUpTask::class);
    }

    /** @param Builder<self> $query */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('received_at')->orderByDesc('id');
    }

    /**
     * Replies still waiting to be looked at.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnreviewed(Builder $query): Builder
    {
        return $query->whereNull('reviewed_at');
    }

    /**
     * Replies worth a human's attention.
     *
     * Excludes out-of-office and automatic replies, which is most of the value
     * of classifying at all.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereNull('reviewed_at')
            ->where(fn (Builder $q) => $q
                ->whereNull('classification')
                ->orWhereNotIn('classification', [
                    ReplyClassification::OutOfOffice->value,
                    ReplyClassification::AutoReply->value,
                ]));
    }

    /**
     * Applies the replies-list filters.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['classification'] ?? null,
                fn (Builder $q, string $v) => $q->where('classification', $v),
            )
            ->when(
                ($filters['reviewed'] ?? null) === 'no',
                fn (Builder $q) => $q->whereNull('reviewed_at'),
            )
            ->when(
                ($filters['reviewed'] ?? null) === 'yes',
                fn (Builder $q) => $q->whereNotNull('reviewed_at'),
            );
    }

    /** A short, safe excerpt for a list row. */
    public function excerpt(int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $this->body) ?? '');

        return mb_strimwidth($text, 0, $length, '...');
    }

    public function needsAttention(): bool
    {
        return $this->reviewed_at === null
            && ($this->classification?->needsAttention() ?? true);
    }
}
