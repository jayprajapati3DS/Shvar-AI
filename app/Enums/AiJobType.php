<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of work that can be handed to a background worker.
 *
 * Every one of these is a call to the local model and nothing else. That is the
 * boundary, and it is the reason Outlook is absent from this list: reading the
 * mailbox and handing a draft to the compose window drive the desktop
 * application over COM, which belongs to the interactive session you are sitting
 * in front of. Those stay in the browser request where you can see them happen.
 *
 * Nothing here sends anything, and nothing here decides anything. A finished job
 * leaves a suggestion on a page for you to accept or throw away, exactly as the
 * synchronous version did - moving the work off the request thread does not move
 * the approval with it.
 */
enum AiJobType: string
{
    case LeadAnalysis = 'lead_analysis';
    case EmailGeneration = 'email_generation';
    case CompanyResearch = 'company_research';
    case ReplyClassification = 'reply_classification';

    public function label(): string
    {
        return match ($this) {
            self::LeadAnalysis => 'Sales analysis',
            self::EmailGeneration => 'Email drafts',
            self::CompanyResearch => 'Website research',
            self::ReplyClassification => 'Reading a reply',
        };
    }

    /**
     * A starting guess at how long this takes on CPU, in seconds.
     *
     * Only ever a fallback. AiJobDurations replaces these with the median of
     * what actually happened on this machine as soon as there are a few runs to
     * go on, because a number written down here is a guess about somebody else's
     * hardware. Measured on qwen3:4b, reading a reply took two minutes eighteen
     * against the thirty seconds originally written here - which is exactly why
     * these are not trusted for long.
     *
     * Whatever the number, the bar is elapsed time and not progress: a local
     * model returns one response at the end and reports nothing on the way. It
     * stays honest by never claiming to be finished, easing towards its ceiling
     * instead of reaching it.
     */
    public function typicalSeconds(): int
    {
        return match ($this) {
            self::LeadAnalysis => 300,
            self::EmailGeneration => 150,
            self::CompanyResearch => 120,
            self::ReplyClassification => 120,
        };
    }
}
