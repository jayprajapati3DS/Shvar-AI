<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single locally saved AI setting.
 *
 * Read and written through App\Services\AI\AiSettings rather than directly -
 * that class owns which keys are permitted and how they fall back to
 * config/ai.php. Notably the endpoint is NOT a permitted key.
 */
class AiSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /** @return array<string, string|null> */
    public static function allValues(): array
    {
        return self::query()->pluck('value', 'key')->all();
    }
}
