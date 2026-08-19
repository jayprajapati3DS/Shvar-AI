<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailLength;
use App\Enums\EmailTone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI email generation run against one lead.
 *
 * Immutable by convention, like LeadAnalysis: regenerating inserts a new row
 * rather than updating this one, so the reasoning behind an email you sent last
 * month is still readable after you have regenerated it twice.
 *
 * Holds the run's accounting - what it personalised on, which product claims it
 * used, what it noticed was missing - because that describes the run rather
 * than the wording of any one variant.
 */
class EmailGeneration extends Model
{
    protected $fillable = [
        'lead_id',
        'contact_id',
        'product_id',
        'lead_product_match_id',
        'provider',
        'model',
        'tone',
        'length',
        'extra_instructions',
        'personalization_points',
        'claims_used',
        'missing_information',
        'warnings',
        'execution_time_ms',
        'ai_request_id',
        'regenerated_from_id',
    ];

    protected function casts(): array
    {
        return [
            'tone' => EmailTone::class,
            'length' => EmailLength::class,
            'personalization_points' => 'array',
            'claims_used' => 'array',
            'missing_information' => 'array',
            'warnings' => 'array',
            'execution_time_ms' => 'integer',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The accepted Phase 3 recommendation this email was written from. */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(LeadProductMatch::class, 'lead_product_match_id');
    }

    /** The Phase 2 log entry holding the exact prompt and raw response. */
    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    /** The earlier run this one was generated to replace, if any. */
    public function regeneratedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }

    /**
     * The three variants this run produced, in the order they are offered.
     *
     * @return HasMany<EmailDraft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(EmailDraft::class)->orderBy('id');
    }

    /** @param Builder<self> $query */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function seconds(): float
    {
        return round(($this->execution_time_ms ?? 0) / 1000, 2);
    }
}
