<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BulkEditable;
use App\Models\Concerns\HasActivities;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BulkEditable;
    use HasActivities;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'job_title',
        'department',
        'email',
        'phone',
        'linkedin_url',
        'country',
        'city',
        'notes',
    ];

    protected $appends = ['full_name'];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return Attribute<string, never> */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * Fields worth setting across many contacts at once.
     *
     * Company is here because reassigning a batch after a merger or a bad
     * import is the common repair.
     *
     * @return list<array<string, mixed>>
     */
    public static function bulkEditableFields(): array
    {
        return [
            // Options are empty here on purpose: the company list is data, not a
            // fixed enum, so ContactController fills it per request. The rule
            // below is what actually protects the write.
            ['key' => 'company_id', 'label' => 'Company', 'type' => 'select', 'nullable' => true,
                'options' => [], 'hint' => 'Moves the selected contacts to this company.',
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
}
