<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a reply appears to be.
 *
 * Assigned by the local model reading the message. It is a reading, not a fact -
 * the UI shows it as the model's opinion and the message body is always there
 * to check it against.
 *
 * The set is deliberately small and operational. "Positive/negative sentiment"
 * would be easier to produce and useless to act on; these are the categories
 * that change what you do next.
 */
enum ReplyClassification: string
{
    case Interested = 'Interested';
    case Question = 'Question';
    case NotNow = 'Not now';
    case NotInterested = 'Not interested';
    case OutOfOffice = 'Out of office';
    case WrongPerson = 'Wrong person';
    case Unsubscribe = 'Unsubscribe';
    case AutoReply = 'Automatic reply';
    case Unclear = 'Unclear';

    public function label(): string
    {
        return $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::Interested => 'emerald',
            self::Question => 'sky',
            self::NotNow => 'amber',
            self::NotInterested => 'red',
            self::Unsubscribe => 'red',
            self::WrongPerson => 'violet',
            self::OutOfOffice, self::AutoReply => 'slate',
            self::Unclear => 'slate',
        };
    }

    /**
     * Whether this reply is worth a human's attention now.
     *
     * An out-of-office is not. Filtering those out is most of the value of
     * classifying at all.
     */
    public function needsAttention(): bool
    {
        return ! in_array($this, [self::OutOfOffice, self::AutoReply], true);
    }

    /**
     * Whether a follow-up task should even be suggested.
     *
     * Never after an unsubscribe or a flat no. Suggesting "chase them again"
     * when someone has said stop is the one output here that could do real
     * damage, so it is refused structurally rather than left to the model.
     */
    public function allowsFollowUp(): bool
    {
        return ! in_array($this, [self::NotInterested, self::Unsubscribe], true);
    }

    /** A sensible number of days to wait before the suggested follow-up. */
    public function suggestedDelayDays(): ?int
    {
        return match ($this) {
            self::Interested, self::Question => 1,
            self::NotNow => 30,
            self::WrongPerson => 3,
            self::OutOfOffice => 7,
            self::Unclear => 5,
            self::AutoReply => null,
            self::NotInterested, self::Unsubscribe => null,
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
