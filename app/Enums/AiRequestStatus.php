<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of a logged AI request.
 *
 * The failure cases map one-to-one onto App\Services\AI\Exceptions, so the log
 * page can show why something failed without storing a stack trace.
 */
enum AiRequestStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Unavailable = 'unavailable';
    case ModelMissing = 'model_missing';
    case Timeout = 'timeout';
    case InvalidResponse = 'invalid_response';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Unavailable => 'Ollama unavailable',
            self::ModelMissing => 'Model not installed',
            self::Timeout => 'Timed out',
            self::InvalidResponse => 'Invalid response',
            self::Rejected => 'Rejected before sending',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Success => 'emerald',
            self::Timeout => 'amber',
            self::Rejected => 'slate',
            self::Unavailable, self::ModelMissing => 'orange',
            self::Failed, self::InvalidResponse => 'red',
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'color' => $case->color(),
        ], self::cases());
    }
}
