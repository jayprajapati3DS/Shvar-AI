<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BulkEditable;
use App\Models\Concerns\HasActivities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use BulkEditable;
    use HasActivities;

    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'website',
        'country',
        'state',
        'city',
        'industry',
        'company_type',
        'description',
        'specialties',
        'products_services',
        'notes',
    ];

    /** @return HasMany<Contact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Website-research runs against this company, newest first.
     *
     * Kept in full: a re-run adds a row rather than replacing one, so what the
     * site said last quarter is still readable after a redesign.
     *
     * @return HasMany<CompanyAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(CompanyAnalysis::class)->latestFirst();
    }

    /**
     * Products attached to any of this company's leads — the "associated
     * products" block on the company detail page.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    public function associatedProducts(): \Illuminate\Support\Collection
    {
        return Product::query()
            ->whereHas('leadMatches.lead', fn (Builder $q) => $q->where('company_id', $this->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * Fields worth setting across many companies at once.
     *
     * Classification and location - what a freshly imported batch tends to
     * share. Name, website and description are per-company by nature.
     *
     * @return list<array<string, mixed>>
     */
    public static function bulkEditableFields(): array
    {
        return [
            ['key' => 'industry', 'label' => 'Industry', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:150']],
            ['key' => 'company_type', 'label' => 'Company type', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:150']],
            ['key' => 'country', 'label' => 'Country', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:120']],
            ['key' => 'state', 'label' => 'State / region', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:120']],
            ['key' => 'city', 'label' => 'City', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:120']],
        ];
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
            ->orWhere('website', 'like', $like)
            ->orWhere('industry', 'like', $like)
            ->orWhere('city', 'like', $like));
    }

    /** Normalised key used to spot duplicates on CSV import. */
    public function getDuplicateKeyAttribute(): string
    {
        return self::normaliseName($this->name);
    }

    public static function normaliseName(?string $name): string
    {
        // Strip punctuation and common suffixes so "Acme Medical, Inc." and
        // "Acme Medical Inc" collide during import.
        $value = strtolower(trim((string) $name));
        $value = preg_replace('/[^a-z0-9 ]/', '', $value) ?? $value;
        $value = preg_replace('/\b(inc|llc|ltd|limited|gmbh|bv|nv|sa|ag|corp|corporation|co|company|plc|pvt|private)\b/', '', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
