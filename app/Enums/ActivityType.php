<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kinds of entry on a lead's activity timeline.
 *
 * Phase 1 renders the timeline and lets you log a Note manually; Email/Call/
 * Meeting/FollowUp are reserved for Phase 2 when email + follow-up automation
 * lands, and are already accepted by the schema.
 */
enum ActivityType: string
{
    case Note = 'Note';
    case Email = 'Email';
    case Call = 'Call';
    case Meeting = 'Meeting';
    case FollowUp = 'Follow-up';
    case StatusChange = 'Status Change';

    public function icon(): string
    {
        return match ($this) {
            self::Note => 'note',
            self::Email => 'mail',
            self::Call => 'phone',
            self::Meeting => 'calendar',
            self::FollowUp => 'clock',
            self::StatusChange => 'arrow',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Note => 'slate',
            self::Email => 'sky',
            self::Call => 'emerald',
            self::Meeting => 'violet',
            self::FollowUp => 'amber',
            self::StatusChange => 'indigo',
        };
    }

    /** Types a user may create by hand in Phase 1. */
    public static function manualCases(): array
    {
        return [self::Note, self::Call, self::Meeting];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string, color: string}> */
    public static function manualOptions(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->value,
            'color' => $case->color(),
        ], self::manualCases());
    }
}
