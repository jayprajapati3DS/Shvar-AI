<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the local AI request log.
 *
 * PRIVACY: rows never leave this machine. Nothing writes them anywhere except
 * database/database.sqlite, and nothing reads them except Settings -> AI Logs.
 */
class AiRequest extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'request_type',
        'lead_id',
        'company_id',
        'status',
        'prompt',
        'response',
        'execution_time_ms',
        'error_message',
        'structured',
        'template',
        'prompt_tokens',
        'response_tokens',
        'temperature',
    ];

    protected function casts(): array
    {
        return [
            'request_type' => AiRequestType::class,
            'status' => AiRequestStatus::class,
            'structured' => 'boolean',
            'execution_time_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'response_tokens' => 'integer',
            'temperature' => 'float',
        ];
    }

    /**
     * The lead this request concerned, when it concerned one.
     *
     * Null for Playground runs and connection tests.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The company this request concerned, when it concerned one.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @param Builder<self> $query */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', '!=', AiRequestStatus::Success->value);
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, string $v) => $q->where('request_type', $v))
            ->when($filters['model'] ?? null, fn (Builder $q, string $v) => $q->where('model', $v))
            ->when($filters['search'] ?? null, function (Builder $q, string $v) {
                $like = '%'.str_replace('%', '\%', $v).'%';

                return $q->where(fn (Builder $inner) => $inner
                    ->where('prompt', 'like', $like)
                    ->orWhere('response', 'like', $like));
            });
    }

    public function seconds(): ?float
    {
        return $this->execution_time_ms === null
            ? null
            : round($this->execution_time_ms / 1000, 2);
    }
}
