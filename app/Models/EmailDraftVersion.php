<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved state of a draft's text.
 *
 * Insert-only. Version 1 is always what the AI wrote; each save appends. The
 * application never updates or deletes a row here, which is what makes "do not
 * overwrite the original AI-generated content" a property of the schema rather
 * than a promise in a controller.
 */
class EmailDraftVersion extends Model
{
    /** Written by the model, or by the user. */
    public const SOURCE_AI = 'ai';

    public const SOURCE_USER = 'user';

    protected $fillable = [
        'email_draft_id',
        'version',
        'subject',
        'body',
        'source',
        'word_count',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'word_count' => 'integer',
        ];
    }

    /** @return BelongsTo<EmailDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(EmailDraft::class, 'email_draft_id');
    }

    public function isAi(): bool
    {
        return $this->source === self::SOURCE_AI;
    }
}
