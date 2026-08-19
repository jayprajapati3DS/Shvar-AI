<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use App\Enums\AiRequestStatus;

/**
 * Rejected before any request is made. Guards against an accidental paste of a
 * whole document exhausting memory or stalling the machine for minutes.
 */
class PromptTooLargeException extends AiException
{
    public function __construct(
        private readonly int $given,
        private readonly int $limit,
    ) {
        parent::__construct("Prompt is {$given} characters; limit is {$limit}");
    }

    public function status(): AiRequestStatus
    {
        return AiRequestStatus::Rejected;
    }

    public function userMessage(): string
    {
        return sprintf(
            'That prompt is too long (%s characters). The limit is %s.',
            number_format($this->given),
            number_format($this->limit),
        );
    }

    public function hint(): string
    {
        return 'Shorten the prompt, or raise AI_MAX_PROMPT_CHARS in .env.';
    }
}
