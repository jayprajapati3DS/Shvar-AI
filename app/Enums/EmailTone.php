<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The user's preferred writing register, set once in Settings and passed into
 * every generation prompt.
 *
 * Distinct from EmailVariant: the variant is the angle of one of the three
 * generated options, the tone is how all three should sound.
 */
enum EmailTone: string
{
    case Professional = 'Professional';
    case Consultative = 'Consultative';
    case Technical = 'Technical';
    case Executive = 'Executive';
    case Friendly = 'Friendly';

    /** The instruction handed to the model for this tone. */
    public function instruction(): string
    {
        return match ($this) {
            self::Professional => 'Write in a professional, neutral business register. Courteous but not warm.',
            self::Consultative => 'Write as an advisor exploring whether there is a fit, not as a vendor pitching.',
            self::Technical => 'Write for a technical reader. Be precise about workflow and capability; skip '
                .'commercial framing.',
            self::Executive => 'Write for a senior decision-maker. Lead with the business relevance; keep it brief.',
            self::Friendly => 'Write in a warm, approachable register while staying professional. Never casual '
                .'or over-familiar.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->value,
        ], self::cases());
    }
}
