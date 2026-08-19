<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an account has got to.
 *
 * Distinct from LeadStatus, which tracks one PERSON. This tracks the company -
 * the thing actually being won. You might work five people at a firm and win
 * the firm once, and "has Dana replied yet" is not the same question as "are we
 * going to land Craniofax".
 *
 * Deliberately short. A pipeline with eleven stages is one where nobody agrees
 * what stage anything is in.
 */
enum CompanyStatus: string
{
    case Prospect = 'Prospect';
    case Engaged = 'Engaged';
    case Negotiating = 'Negotiating';
    case Won = 'Won';
    case Lost = 'Lost';
    case Dormant = 'Dormant';

    public function label(): string
    {
        return $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::Prospect => 'slate',
            self::Engaged => 'sky',
            self::Negotiating => 'amber',
            self::Won => 'emerald',
            self::Lost => 'red',
            self::Dormant => 'slate',
        };
    }

    /** Whether this account is still being worked. */
    public function isActive(): bool
    {
        return in_array($this, [self::Prospect, self::Engaged, self::Negotiating], true);
    }

    /** Whether the outcome is settled, one way or the other. */
    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    /**
     * A short description, shown under the picker.
     *
     * Worth spelling out: a pipeline is only useful if two people would put the
     * same account in the same stage.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Prospect => 'Identified, not yet in conversation.',
            self::Engaged => 'Someone there is talking to us.',
            self::Negotiating => 'Discussing terms, pricing or a trial.',
            self::Won => 'We are doing business with them.',
            self::Lost => 'They said no, or went elsewhere.',
            self::Dormant => 'Parked. Not now, but not a no.',
        };
    }

    /** The order stages appear in a pipeline view. */
    public static function pipeline(): array
    {
        return [self::Prospect, self::Engaged, self::Negotiating, self::Won];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string, color: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'color' => $case->color(),
            'description' => $case->describe(),
        ], self::cases());
    }
}
