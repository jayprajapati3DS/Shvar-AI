<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a single product recommendation.
 *
 * Orthogonal to RecommendationType, which records where it came from (Manual /
 * AI Primary / AI Secondary). This records what the human decided about it.
 *
 * Nothing moves out of Suggested without an explicit action by the user - the
 * AI never accepts its own recommendation.
 */
enum RecommendationStatus: string
{
    case Suggested = 'Suggested';
    case Accepted = 'Accepted';
    case Rejected = 'Rejected';
    case Archived = 'Archived';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Awaiting review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Suggested => 'sky',
            self::Accepted => 'emerald',
            self::Rejected => 'red',
            self::Archived => 'slate',
        };
    }

    /** Whether this recommendation still counts as a live opportunity. */
    public function isActive(): bool
    {
        return $this === self::Suggested || $this === self::Accepted;
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
