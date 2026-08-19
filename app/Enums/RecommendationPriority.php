<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How strongly a recommended product should be pushed.
 *
 * Distinct from confidence_score: priority is the AI's judgement of *pitch
 * order*, confidence is how well the evidence supports the match at all. A
 * product can be High priority with moderate confidence (obvious fit, thin
 * data) or Low priority with high confidence (clear fit, but not the opener).
 */
enum RecommendationPriority: string
{
    case High = 'High';
    case Medium = 'Medium';
    case Low = 'Low';

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
            self::High => 'emerald',
            self::Medium => 'amber',
            self::Low => 'slate',
        };
    }

    /** Tolerant parse - models return "high", "HIGH", " High " and so on. */
    public static function parse(mixed $value, self $fallback = self::Medium): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom(ucfirst(strtolower(trim((string) $value)))) ?? $fallback;
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
