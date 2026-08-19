<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Joins a lead to a product it might be sold.
 *
 * Two provenances, deliberately kept apart:
 *
 *   recommendation_type  where it came from  (Manual | AI Primary | AI Secondary)
 *   status               what you decided    (Suggested | Accepted | Rejected | Archived)
 *
 * A manual pick is created Accepted - choosing it IS the decision. AI output is
 * created Suggested and stays there until you act on it; nothing in the
 * application promotes an AI recommendation on its own.
 *
 * Rows are never overwritten by a re-analysis: each run inserts its own, linked
 * to its lead_analysis_id, so the history stays intact. This is why the original
 * unique(lead_id, product_id) constraint was dropped.
 */
class LeadProductMatch extends Model
{
    protected $table = 'lead_product_matches';

    protected $fillable = [
        'lead_id',
        'product_id',
        'lead_analysis_id',
        'recommendation_type',
        'priority',
        'status',
        'confidence_score',
        'raw_confidence_score',
        'reason',
        'evidence',
        'sales_angle',
        'suggested_use_case',
        'module',
        'notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'recommendation_type' => RecommendationType::class,
            'priority' => RecommendationPriority::class,
            'status' => RecommendationStatus::class,
            'confidence_score' => 'float',
            'raw_confidence_score' => 'float',
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
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

    /** @return BelongsTo<LeadAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(LeadAnalysis::class, 'lead_analysis_id');
    }

    /** @param Builder<self> $query */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where('recommendation_type', RecommendationType::Manual->value);
    }

    /** @param Builder<self> $query */
    public function scopeAiGenerated(Builder $query): Builder
    {
        return $query->where('recommendation_type', '!=', RecommendationType::Manual->value);
    }

    /**
     * What the lead detail page shows as the live opportunity list: manual picks
     * and accepted AI recommendations, plus anything still awaiting review.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            RecommendationStatus::Suggested->value,
            RecommendationStatus::Accepted->value,
        ]);
    }

    /** Whether the calibrator lowered the model's own score. */
    public function wasCalibrated(): bool
    {
        return $this->raw_confidence_score !== null
            && $this->confidence_score !== null
            && abs($this->raw_confidence_score - $this->confidence_score) > 0.0001;
    }

    public function confidencePercent(): ?int
    {
        return $this->confidence_score === null
            ? null
            : (int) round($this->confidence_score * 100);
    }
}
