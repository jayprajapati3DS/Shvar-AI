<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Concerns\HasActivities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasActivities;

    /** @use HasFactory<\Database\Factories\LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'contact_id',
        'lead_source',
        'lead_status',
        'priority',
        'assigned_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'lead_status' => LeadStatus::class,
            'priority' => Priority::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<LeadProductMatch, $this> */
    public function productMatches(): HasMany
    {
        return $this->hasMany(LeadProductMatch::class);
    }

    /**
     * Every AI analysis run against this lead, newest first.
     *
     * Kept in full: a re-analysis adds a row rather than replacing one, so the
     * reasoning behind an earlier recommendation stays inspectable.
     *
     * @return HasMany<LeadAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(LeadAnalysis::class)->latestFirst();
    }

    /** @return HasOne<LeadAnalysis, $this> */
    public function latestAnalysis(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LeadAnalysis::class)->latestOfMany();
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'lead_product_matches')
            ->withPivot(['id', 'recommendation_type', 'confidence_score', 'reason', 'notes'])
            ->withTimestamps();
    }

    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereHas('company', fn (Builder $c) => $c->where('name', 'like', $like))
            ->orWhereHas('contact', fn (Builder $c) => $c
                ->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)));
    }

    /**
     * Applies the lead-list filters. Unknown or blank values are ignored so a
     * half-filled filter bar never empties the table by accident.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('lead_status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $v) => $q->where('priority', $v))
            ->when($filters['company_id'] ?? null, fn (Builder $q, string $v) => $q->where('company_id', $v))
            ->when($filters['source'] ?? null, fn (Builder $q, string $v) => $q->where('lead_source', $v))
            ->when(
                $filters['country'] ?? null,
                fn (Builder $q, string $v) => $q->whereHas('company', fn (Builder $c) => $c->where('country', $v))
            );
    }

    /** @param Builder<self> $query */
    public function scopeOpportunities(Builder $query): Builder
    {
        return $query->whereIn(
            'lead_status',
            array_map(fn (LeadStatus $s) => $s->value, LeadStatus::opportunityStages())
        );
    }
}
