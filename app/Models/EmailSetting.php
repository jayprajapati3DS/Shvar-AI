<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single locally saved email setting - a signature field or a writing
 * preference.
 *
 * Read and written through App\Services\Email\EmailSettings, which owns which
 * keys are permitted. Kept separate from AiSetting so that whitelist stays a
 * clear security boundary rather than a mixed bag.
 */
class EmailSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /** @return array<string, string|null> */
    public static function allValues(): array
    {
        return self::query()->pluck('value', 'key')->all();
    }
}
