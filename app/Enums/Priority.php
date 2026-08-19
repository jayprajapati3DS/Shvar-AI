<?php

declare(strict_types=1);

namespace App\Enums;

enum Priority: string
{
    case Low = 'Low';
    case Medium = 'Medium';
    case High = 'High';

    /** Higher weight sorts first in the lead list. */
    public function weight(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::High => 'red',
            self::Medium => 'amber',
            self::Low => 'slate',
        };
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
            'label' => $case->value,
            'color' => $case->color(),
        ], self::cases());
    }
}
