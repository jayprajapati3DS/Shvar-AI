<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How long the generated email should be.
 *
 * The word bands are also what EmailQualityChecker measures against, so the
 * setting the user picked is the same number the quality panel judges by.
 */
enum EmailLength: string
{
    case Short = 'Short';
    case Standard = 'Standard';
    case Detailed = 'Detailed';

    /** @return array{0: int, 1: int} inclusive word range */
    public function range(): array
    {
        return match ($this) {
            self::Short => [60, 110],
            self::Standard => [100, 180],
            self::Detailed => [160, 260],
        };
    }

    public function min(): int
    {
        return $this->range()[0];
    }

    public function max(): int
    {
        return $this->range()[1];
    }

    public function instruction(): string
    {
        [$min, $max] = $this->range();

        return "Aim for {$min}-{$max} words in the body, excluding the signature.";
    }

    public function label(): string
    {
        [$min, $max] = $this->range();

        return "{$this->value} ({$min}-{$max} words)";
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
            'label' => $case->label(),
        ], self::cases());
    }
}
