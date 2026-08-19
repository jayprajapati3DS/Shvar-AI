<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI analysis run against one lead.
 *
 * Immutable by convention: a re-analysis creates a new row rather than
 * updating this one, so the reasoning behind an old recommendation stays
 * inspectable. Nothing in the application updates an existing analysis.
 */
class LeadAnalysis extends Model
{
    protected $table = 'lead_analyses';

    protected $fillable = [
        'lead_id',
        'provider',
        'model',
        'company_summary',
        'company_type',
        'business_opportunity',
        'products_to_avoid',
        'missing_information',
        'recommended_next_action',
        'warnings',
        'primary_confidence',
        'primary_product_id',
        'execution_time_ms',
        'ai_request_id',
    ];

    protected function casts(): array
    {
        return [
            'products_to_avoid' => 'array',
            'missing_information' => 'array',
            'warnings' => 'array',
            'primary_confidence' => 'float',
            'execution_time_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function primaryProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'primary_product_id');
    }

    /** The Phase 2 log entry holding the exact prompt and raw response. */
    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    /**
     * Recommendations produced by this run, best pitch first.
     *
     * Ordered in SQL rather than in PHP so the detail page and the history list
     * agree without either having to re-sort.
     *
     * @return HasMany<LeadProductMatch, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(LeadProductMatch::class)
            ->orderByRaw("CASE priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END")
            ->orderByDesc('confidence_score');
    }

    /** @param Builder<self> $query */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function seconds(): ?float
    {
        return $this->execution_time_ms === null
            ? null
            : round($this->execution_time_ms / 1000, 2);
    }
}
