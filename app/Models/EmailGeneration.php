<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailLength;
use App\Enums\EmailTone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The PRIMARY accepted recommendation - the product this email leads with.
     *
     * Denormalised onto the generation rather than reached through the pivot,
     * so "what is this email mainly about" stays one column and every existing
     * filter keeps working.
     */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(LeadProductMatch::class, 'lead_product_match_id');
    }

    /**
     * Every recommendation this email was written from, primary first.
     *
     * An email may pitch more than one product. The order is the order the user
     * chose, which is the order the model is told to weigh them in.
     *
     * @return BelongsToMany<LeadProductMatch, $this>
     */
    public function recommendations(): BelongsToMany
    {
        return $this->belongsToMany(
            LeadProductMatch::class,
            'email_generation_recommendations',
            'email_generation_id',
            'lead_product_match_id',
        )
            ->withPivot(['is_primary', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * The products pitched, in order.
     *
     * @return list<string>
     */
    public function productNames(): array
    {
        return $this->recommendations
            ->map(fn (LeadProductMatch $m) => $m->product?->name)
            ->filter()
            ->values()
            ->all();
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
