<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lead lifecycle. Stored as the string value so the column stays readable
 * in raw SQL and survives a move to PostgreSQL without a type migration.
 */
enum LeadStatus: string
{
    case New = 'New';
    case Researching = 'Researching';
    case Qualified = 'Qualified';
    case EmailDrafted = 'Email Drafted';
    case Approved = 'Approved';
    case Contacted = 'Contacted';
    case FollowUp = 'Follow-up';
    case Interested = 'Interested';
    case MeetingScheduled = 'Meeting Scheduled';
    case Proposal = 'Proposal';
    case Negotiation = 'Negotiation';
    case Won = 'Won';
    case Lost = 'Lost';
    case NotRelevant = 'Not Relevant';

    /**
     * The ordered stages drawn in the dashboard pipeline. Statuses outside this
     * list (Email Drafted, Approved, Follow-up, Not Relevant) are working states
     * rather than pipeline stages, so they are counted but not charted.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [
            self::New,
            self::Researching,
            self::Qualified,
            self::Contacted,
            self::Interested,
            self::MeetingScheduled,
            self::Proposal,
            self::Negotiation,
            self::Won,
            self::Lost,
        ];
    }

    /** Statuses that represent a live revenue opportunity. */
    public static function opportunityStages(): array
    {
        return [self::Interested, self::MeetingScheduled, self::Proposal, self::Negotiation];
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Won, self::Lost, self::NotRelevant], true);
    }

    /** Tailwind colour token used by the status badge on the frontend. */
    public function color(): string
    {
        return match ($this) {
            self::New => 'slate',
            self::Researching, self::EmailDrafted => 'sky',
            self::Qualified, self::Approved => 'indigo',
            self::Contacted, self::FollowUp => 'amber',
            self::Interested, self::MeetingScheduled => 'violet',
            self::Proposal, self::Negotiation => 'orange',
            self::Won => 'emerald',
            self::Lost, self::NotRelevant => 'red',
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
            'label' => $case->value,
            'color' => $case->color(),
        ], self::cases());
    }
}
