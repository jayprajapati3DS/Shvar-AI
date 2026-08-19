<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email the user could send.
 *
 * subject/body are the live text and change as the user edits. ai_subject and
 * ai_body are written once at generation and never touched again, so "what did
 * the model actually write" always has an answer no matter how heavily the
 * draft was rewritten afterwards.
 *
 * The status rules live on EmailDraftStatus rather than here, so every caller
 * asks the same question the same way. The one that matters: only Approved is
 * sendable, and approving is the only thing that sets approved_at.
 */
class EmailDraft extends Model
{
    protected $fillable = [
        'email_generation_id',
        'lead_id',
        'contact_id',
        'product_id',
        'lead_product_match_id',
        'variant',
        'status',
        'subject',
        'body',
        'ai_subject',
        'ai_body',
        'recipient_email',
        'recipient_name',
        'personalization_points',
        'quality',
        'word_count',
        'ai_model',
        'ai_request_id',
        'created_by',
        'version',
        'approved_at',
        'sent_at',
        'delivery_mode',
        'delivery_reference',
        'delivery_error',
    ];

    protected function casts(): array
    {
        return [
            'variant' => EmailVariant::class,
            'status' => EmailDraftStatus::class,
            'personalization_points' => 'array',
            'quality' => 'array',
            'word_count' => 'integer',
            'version' => 'integer',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmailGeneration, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(EmailGeneration::class, 'email_generation_id');
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<LeadProductMatch, $this> */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(LeadProductMatch::class, 'lead_product_match_id');
    }

    /**
     * Every state this draft's text has been in, oldest first.
     *
     * @return HasMany<EmailDraftVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(EmailDraftVersion::class)->orderBy('version');
    }

    /* ---------------------------------------------------------------------- */
    /* Scopes */
    /* ---------------------------------------------------------------------- */

    /** @param Builder<self> $query */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Drafts still moving through the workflow.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            EmailDraftStatus::Draft->value,
            EmailDraftStatus::UserEdited->value,
            EmailDraftStatus::Approved->value,
        ]);
    }

    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('subject', 'like', $like)
            ->orWhere('recipient_name', 'like', $like)
            ->orWhere('recipient_email', 'like', $like)
            ->orWhereHas('lead.company', fn (Builder $c) => $c->where('name', 'like', $like)));
    }

    /**
     * Applies the drafts-list filters. Blank values are ignored so a
     * half-filled filter bar never empties the table by accident.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['variant'] ?? null, fn (Builder $q, string $v) => $q->where('variant', $v))
            ->when($filters['product_id'] ?? null, fn (Builder $q, string $v) => $q->where('product_id', $v))
            ->when(
                $filters['company_id'] ?? null,
                fn (Builder $q, string $v) => $q->whereHas('lead', fn (Builder $l) => $l->where('company_id', $v)),
            )
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v));
    }

    /* ---------------------------------------------------------------------- */
    /* State */
    /* ---------------------------------------------------------------------- */

    public function isSendable(): bool
    {
        return $this->status->isSendable();
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isApprovable(): bool
    {
        return $this->status->isApprovable();
    }

    /** Whether the user has changed the model's wording. */
    public function wasEdited(): bool
    {
        return $this->ai_subject !== null
            && ($this->subject !== $this->ai_subject || $this->body !== $this->ai_body);
    }

    /** Words in the body, the same count the quality check uses. */
    public static function countWords(string $body): int
    {
        return count(preg_split('/\s+/u', trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
