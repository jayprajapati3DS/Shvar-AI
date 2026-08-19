<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BulkEditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BulkEditable;

    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'short_description',
        'detailed_description',
        'target_customer',
        'target_specialty',
        'key_features',
        'value_proposition',
        'sales_notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<LeadProductMatch, $this> */
    public function leadMatches(): HasMany
    {
        return $this->hasMany(LeadProductMatch::class);
    }

    /** @return BelongsToMany<Lead, $this> */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_product_matches')
            ->withPivot(['id', 'recommendation_type', 'confidence_score', 'reason', 'notes'])
            ->withTimestamps();
    }

    /**
     * Fields worth setting across many products at once.
     *
     * @return list<array<string, mixed>>
     */
    public static function bulkEditableFields(): array
    {
        return [
            ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:180']],
            [
                'key' => 'active',
                'label' => 'Active',
                'type' => 'boolean',
                'hint' => 'Inactive products are hidden from lead product pickers and never sent to the AI.',
                'rules' => ['boolean'],
            ],
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** @param Builder<self> $query */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('category', 'like', $like)
            ->orWhere('short_description', 'like', $like)
            ->orWhere('target_customer', 'like', $like));
    }

    /**
     * `target_customer` / `key_features` / `target_specialty` are stored as
     * newline-separated text so they stay greppable in SQL and editable in a
     * plain textarea. These helpers split them for list rendering.
     *
     * @return list<string>
     */
    public static function toLines(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\r\n|\r|\n/', $value) ?: []),
            fn (string $line) => $line !== ''
        ));
    }
}
