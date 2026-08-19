<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Concerns\BulkEditable;
use App\Models\Concerns\HasActivities;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * A person at a company you are trying to win.
 *
 * A lead IS the person. Contact used to be a separate record and the two were
 * nearly 1:1 - a contact had a name and no pipeline, a lead had a pipeline and
 * no name - which meant entering the same human twice. They are one thing now.
 *
 * The company is the account being won; leads are the routes into it. Several
 * leads per company is the normal case: a surgeon, a procurement lead and a head
 * of engineering are three ways into the same deal.
 *
 * So there are two statuses in the system and they answer different questions:
 * lead_status is where THIS PERSON has got to, Company::$status is where the
 * ACCOUNT has got to.
 */
class Lead extends Model
{
    use BulkEditable;
    use HasActivities;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',

        // The person.
        'first_name',
        'last_name',
        'job_title',
        'department',
        'email',
        'phone',
        'linkedin_url',
        'country',
        'city',

        // The pursuit.
        'lead_source',
        'lead_status',
        'priority',
        'assigned_to',
        'notes',
    ];

    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return [
            'lead_status' => LeadStatus::class,
            'priority' => Priority::class,
        ];
    }

    /**
     * The person's name.
     *
     * Falls back to the email, then to the company, then to the id - a lead
     * row always has to render as something, and "Lead #12" is more use than an
     * empty cell.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(function (): string {
            $name = trim($this->first_name.' '.$this->last_name);

            if ($name !== '') {
                return $name;
            }

            if (filled($this->email)) {
                return (string) $this->email;
            }

            return $this->relationLoaded('company') && $this->company !== null
                ? $this->company->name
                : "Lead #{$this->id}";
        });
    }

    /** Whether this lead is a named person rather than just a company row. */
    public function isNamed(): bool
    {
        return trim($this->first_name.' '.$this->last_name) !== '';
    }

    /** Whether an email could actually be sent to this lead. */
    public function isContactable(): bool
    {
        return filled($this->email);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    /**
     * Fields worth setting across many leads at once.
     *
     * Status and priority are the day-to-day case: qualifying a batch after a
     * conference, or dropping a stale segment down the pipeline.
     *
     * @return list<array<string, mixed>>
     */
    public static function bulkEditableFields(): array
    {
        return [
            // Not nullable: a lead always has a status and a priority, so the
            // modal offers no way to clear them.
            ['key' => 'lead_status', 'label' => 'Lead status', 'type' => 'select',
                'options' => LeadStatus::options(),
                'rules' => [Rule::enum(LeadStatus::class)]],
            ['key' => 'priority', 'label' => 'Priority', 'type' => 'select',
                'options' => Priority::options(),
                'rules' => [Rule::enum(Priority::class)]],
            ['key' => 'lead_source', 'label' => 'Lead source', 'type' => 'text', 'nullable' => true,
                'hint' => 'Free text - anything you could type on a single lead.',
                'rules' => ['string', 'max:150']],
            ['key' => 'assigned_to', 'label' => 'Assigned to', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:150']],

            // The person's own fields, now that a lead IS the person. Company
            // is here because moving a batch of people to the right account is
            // exactly the tidy-up a merged model creates.
            ['key' => 'company_id', 'label' => 'Company', 'type' => 'select', 'nullable' => true,
                'options' => [], 'hint' => 'Moves the selected leads to this company.',
                'rules' => ['integer', 'exists:companies,id']],
            ['key' => 'department', 'label' => 'Department', 'type' => 'text', 'nullable' => true,
                'rules' => ['string', 'max:150']],
            ['key' => 'country', 'label' => 'Country', 'type' => 'text', 'nullable' => true,
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
            ->where('first_name', 'like', $like)
            ->orWhere('last_name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->orWhere('job_title', 'like', $like)
            ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like)));
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
            ->when($filters['department'] ?? null, fn (Builder $q, string $v) => $q->where('department', $v))
            // Leads with nobody named on them, or nobody reachable - the two
            // states that quietly stop outreach working.
            ->when(
                ($filters['gap'] ?? null) === 'no_email',
                fn (Builder $q) => $q->where(fn (Builder $i) => $i->whereNull('email')->orWhere('email', '')),
            )
            ->when(
                ($filters['gap'] ?? null) === 'no_name',
                fn (Builder $q) => $q->where(fn (Builder $i) => $i->whereNull('first_name')->orWhere('first_name', '')),
            )
            ->when(
                ($filters['gap'] ?? null) === 'no_company',
                fn (Builder $q) => $q->whereNull('company_id'),
            )
            ->when($filters['source'] ?? null, fn (Builder $q, string $v) => $q->where('lead_source', $v))
            // The person has their own country now, but it is usually blank and
            // the company's is what people mean. Match either.
            ->when(
                $filters['country'] ?? null,
                fn (Builder $q, string $v) => $q->where(fn (Builder $i) => $i
                    ->where('country', $v)
                    ->orWhereHas('company', fn (Builder $c) => $c->where('country', $v)))
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
