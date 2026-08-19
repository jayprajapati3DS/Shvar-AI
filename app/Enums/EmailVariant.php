<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three angles the model writes each email from.
 *
 * Values are the snake_case strings the model returns in its JSON, so the enum
 * doubles as the schema's allowed list and the parser's whitelist - a variant
 * name the model invents fails to resolve and is rejected rather than stored.
 */
enum EmailVariant: string
{
    case ProfessionalDirect = 'professional_direct';
    case Consultative = 'consultative';
    case ExecutiveShort = 'executive_short';

    public function label(): string
    {
        return match ($this) {
            self::ProfessionalDirect => 'Professional / Direct',
            self::Consultative => 'Consultative / Problem-focused',
            self::ExecutiveShort => 'Short / Executive-friendly',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::ProfessionalDirect => 'Direct',
            self::Consultative => 'Consultative',
            self::ExecutiveShort => 'Executive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ProfessionalDirect => 'indigo',
            self::Consultative => 'violet',
            self::ExecutiveShort => 'sky',
        };
    }

    /** What the model is told this variant should sound like. */
    public function brief(): string
    {
        return match ($this) {
            self::ProfessionalDirect => 'Professional and direct. State plainly who you are, why you '
                .'are writing and what you are offering. No preamble.',
            self::Consultative => 'Consultative and problem-focused. Lead with the workflow problem or '
                .'opportunity their situation suggests, then connect the product to it.',
            self::ExecutiveShort => 'Short and executive-friendly. Under 90 words. One idea, one '
                .'sentence of relevance, one clear ask. No detail a director would skip.',
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
            'label' => $case->label(),
            'color' => $case->color(),
        ], self::cases());
    }
}
