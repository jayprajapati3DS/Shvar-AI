<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a queued piece of AI work has got to.
 *
 * Deliberately small. There is no "retrying" state because these jobs run once
 * - re-running a five-minute analysis behind the user's back is not a kindness
 * - and no "paused", because nothing here can be paused halfway.
 *
 * The distinction that earns its keep is Queued vs Running. Queued means the
 * request was accepted but no worker has picked it up; if that lasts, the queue
 * worker is not running and the user needs to be told, not left watching a
 * spinner that will never move.
 */
enum AiJobStatus: string
{
    case Queued = 'Queued';
    case Running = 'Running';
    case Done = 'Done';
    case Failed = 'Failed';
    case Cancelled = 'Cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting',
            self::Running => 'Working',
            self::Done => 'Done',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'slate',
            self::Running => 'indigo',
            self::Done => 'emerald',
            self::Failed => 'red',
            self::Cancelled => 'slate',
        };
    }

    /** Still expected to produce something. */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    /** Nothing more will happen to it. */
    public function isFinished(): bool
    {
        return ! $this->isActive();
    }
}
