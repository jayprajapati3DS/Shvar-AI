<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a lead/product pairing came to exist.
 *
 * Phase 1 only ever writes `Manual` — a human picks the product on the lead
 * detail page. The AI cases exist now so the Phase 2 recommendation service can
 * write rows without a schema change, and so the UI can already distinguish a
 * human-chosen match from a machine-suggested one.
 */
enum RecommendationType: string
{
    case Manual = 'Manual';
    case AiSuggested = 'AI Suggested';
    case AiPrimary = 'AI Primary';
    case AiSecondary = 'AI Secondary';

    public function isAiGenerated(): bool
    {
        return $this !== self::Manual;
    }

    public function color(): string
    {
        return match ($this) {
            self::Manual => 'slate',
            self::AiPrimary => 'indigo',
            self::AiSecondary => 'sky',
            self::AiSuggested => 'violet',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Only the options a human may pick in Phase 1.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function manualOptions(): array
    {
        return [[
            'value' => self::Manual->value,
            'label' => self::Manual->value,
            'color' => self::Manual->color(),
        ]];
    }
}
