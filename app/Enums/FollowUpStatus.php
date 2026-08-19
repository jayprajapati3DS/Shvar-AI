<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a follow-up task has got to.
 *
 * Dismissed is separate from Done on purpose: "I did this" and "this was never
 * worth doing" are different facts, and collapsing them would make the AI's
 * suggestions look more useful than they were.
 */
enum FollowUpStatus: string
{
    case Open = 'Open';
    case Done = 'Done';
    case Dismissed = 'Dismissed';

    public function label(): string
    {
        return $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'sky',
            self::Done => 'emerald',
            self::Dismissed => 'slate',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
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
