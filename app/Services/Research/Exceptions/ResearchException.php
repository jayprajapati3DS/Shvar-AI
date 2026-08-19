<?php

declare(strict_types=1);

namespace App\Services\Research\Exceptions;

use RuntimeException;

/**
 * Base for every website-research failure.
 *
 * Carries two messages, matching the Phase 2 AiException convention:
 * getMessage() is technical and goes to the log; userMessage() is plain
 * language and is the only thing the browser ever sees.
 */
abstract class ResearchException extends RuntimeException
{
    abstract public function userMessage(): string;

    public function hint(): ?string
    {
        return null;
    }
}
