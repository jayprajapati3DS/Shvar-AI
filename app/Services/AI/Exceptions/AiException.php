<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use App\Enums\AiRequestStatus;
use RuntimeException;

/**
 * Base class for every AI failure.
 *
 * Carries two separate messages on purpose:
 *
 *   getMessage()   - technical detail, written to the local ai_requests log
 *   userMessage()  - plain language, safe to render in the UI
 *
 * Controllers only ever surface userMessage(), so a connection refusal or a
 * malformed payload can never leak a stack trace or an internal path to the
 * browser.
 */
abstract class AiException extends RuntimeException
{
    /** The status recorded against this failure in the local log. */
    abstract public function status(): AiRequestStatus;

    /** Plain-language message shown to the user. */
    abstract public function userMessage(): string;

    /**
     * Optional concrete next step, shown under the message.
     */
    public function hint(): ?string
    {
        return null;
    }
}
