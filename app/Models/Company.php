<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Models\Concerns\BulkEditable;
use App\Models\Concerns\HasActivities;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * An account: the thing you are actually trying to win.
 *
 * Leads are the people you go through to win it, and several per company is the
 * normal case - a surgeon, a procurement lead and a head of engineering are
 * three routes into the same deal.
 *
 * So the pipeline lives HERE, not on the lead. Company::$status answers "are we
 * going to land Craniofax"; a lead's status answers "has Dana replied yet".
 * Those are different questions and collapsing them would lose one of them.
 */
class Company extends Model
{
    use BulkEditable;
    use HasActivities;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'website',
        'status',
        'won_at',
        'lost_at',
        'outcome_reason',
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

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Leads with an email address - the ones outreach can actually reach.
     *
     * A named person with no address is still worth recording, but it cannot be
     * written to, and a company page that shows five contacts and can email
     * none of them is misleading.
     *
     * @return HasMany<Lead, $this>
     */
    public function contactableLeads(): HasMany
    {
        return $this->leads()->whereNotNull('email')->where('email', '!=', '');
    }

    /* ---------------------------------------------------------------------- */
    /* Pipeline */
    /* ---------------------------------------------------------------------- */

    /**
     * Move the account to a new stage.
     *
     * Stamps won_at / lost_at rather than leaving the date to be inferred from
     * an activity entry, and clears the other one - an account that was lost
     * and later won should not still carry a lost date.
     */
    public function moveTo(CompanyStatus $status, ?string $reason = null): self
    {
        $this->update([
            'status' => $status,
            'won_at' => $status === CompanyStatus::Won ? ($this->won_at ?? now()) : null,
            'lost_at' => $status === CompanyStatus::Lost ? ($this->lost_at ?? now()) : null,
            'outcome_reason' => $status->isClosed() ? $reason : null,
        ]);

        return $this;
    }

    public function isWon(): bool
    {
        return $this->status === CompanyStatus::Won;
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CompanyStatus::Prospect->value,
            CompanyStatus::Engaged->value,
            CompanyStatus::Negotiating->value,
        ]);
    }

    /** @param Builder<self> $query */
    public function scopeWon(Builder $query): Builder
    {
        return $query->where('status', CompanyStatus::Won->value);
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
     * @return Collection<int, Product>
     */
    public function associatedProducts(): Collection
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
            ['key' => 'status', 'label' => 'Account status', 'type' => 'select',
                'options' => CompanyStatus::options(),
                'rules' => [Rule::enum(CompanyStatus::class)]],
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
