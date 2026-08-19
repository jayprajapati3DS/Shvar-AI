<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use App\Enums\AiRequestStatus;

/**
 * Ollama answered, but not with something usable: an unexpected payload shape,
 * an empty completion, or - for a structured request - text that is not valid
 * JSON even after extraction.
 */
class InvalidAiResponseException extends AiException
{
    public function __construct(
        string $technicalMessage,
        private readonly bool $structured = false,
    ) {
        parent::__construct($technicalMessage);
    }

    public function status(): AiRequestStatus
    {
        return AiRequestStatus::InvalidResponse;
    }

    public function userMessage(): string
    {
        return $this->structured
            ? 'The local model did not return valid structured data.'
            : 'The local model returned a response that could not be read.';
    }

    public function hint(): string
    {
        return $this->structured
            ? 'Smaller models are less reliable at strict JSON. Try a larger model, or lower the temperature.'
            : 'Try again. If it keeps happening, check the Ollama logs.';
    }
}
