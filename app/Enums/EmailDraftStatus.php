<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of one email draft.
 *
 * The important property is that Sent is unreachable except through Approved.
 * A draft the AI just wrote, and a draft the user has edited, are both
 * unsendable - the human has to say so explicitly, and approve() is the only
 * transition that sets approved_at.
 *
 *   Draft ──edit──> User Edited ──approve──> Approved ──send──> Sent
 *     │                  │                      │                 │
 *     └──────────────────┴───── reject ─────────┘            (or Failed)
 *
 * Archived is the "get it off my list" state and judges nothing.
 */
enum EmailDraftStatus: string
{
    case Draft = 'Draft';
    case UserEdited = 'User Edited';
    case Approved = 'Approved';
    case Queued = 'Queued';
    case Sent = 'Sent';
    case Failed = 'Failed';
    case Rejected = 'Rejected';
    case Archived = 'Archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'AI generated',
            self::UserEdited => 'User edited',
            self::Approved => 'Approved',
            self::Queued => 'Queued',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'sky',
            self::UserEdited => 'amber',
            self::Approved => 'emerald',
            self::Queued => 'violet',
            self::Sent => 'indigo',
            self::Failed => 'red',
            self::Rejected => 'red',
            self::Archived => 'slate',
        };
    }

    /**
     * Whether this draft may be sent.
     *
     * The single most important rule in Phase 4, expressed once so no controller
     * has to remember it. Deliberately NOT true for Draft or User Edited: an
     * unapproved email is never sendable, however finished it looks.
     */
    public function isSendable(): bool
    {
        return $this === self::Approved;
    }

    /** Whether the text can still be changed. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::UserEdited;
    }

    /** Whether the user can still approve it. */
    public function isApprovable(): bool
    {
        return $this === self::Draft || $this === self::UserEdited;
    }

    /** Whether this draft has left the workflow. */
    public function isClosed(): bool
    {
        return $this === self::Sent
            || $this === self::Rejected
            || $this === self::Archived;
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
