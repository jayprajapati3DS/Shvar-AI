<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One website-research run against one company.
 *
 * Holds what the page said, not what the company record says. Findings sit here
 * until you accept them; `applied_fields` records which ones you took.
 *
 * Immutable by convention apart from the review columns - a re-run creates a
 * new row so the history of what a site claimed stays intact.
 */
class CompanyAnalysis extends Model
{
    protected $table = 'company_analyses';

    protected $fillable = [
        'company_id',
        'requested_url',
        'fetched_urls',
        'provider',
        'model',
        'findings',
        'not_found',
        'warnings',
        'page_summary',
        'applied_fields',
        'reviewed_at',
        'fetch_time_ms',
        'execution_time_ms',
        'source_chars',
        'ai_request_id',
    ];

    protected function casts(): array
    {
        return [
            'fetched_urls' => 'array',
            'findings' => 'array',
            'not_found' => 'array',
            'warnings' => 'array',
            'applied_fields' => 'array',
            'reviewed_at' => 'datetime',
            'fetch_time_ms' => 'integer',
            'execution_time_ms' => 'integer',
            'source_chars' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<AiRequest, $this> */
    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    /** @param Builder<self> $query */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** One finding by field name, or null. */
    public function finding(string $field): ?array
    {
        foreach ((array) $this->findings as $finding) {
            if (($finding['field'] ?? null) === $field) {
                return $finding;
            }
        }

        return null;
    }

    public function hasBeenApplied(string $field): bool
    {
        return in_array($field, (array) $this->applied_fields, true);
    }

    public function seconds(): ?float
    {
        $total = (int) $this->fetch_time_ms + (int) $this->execution_time_ms;

        return $total > 0 ? round($total / 1000, 2) : null;
    }
}
