<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use App\Enums\AiRequestStatus;

/** Ollama is not reachable - not installed, not started, or wrong port. */
class AiUnavailableException extends AiException
{
    public function __construct(
        private readonly string $endpoint,
        string $technicalMessage = '',
    ) {
        parent::__construct($technicalMessage !== '' ? $technicalMessage : "No response from {$endpoint}");
    }

    public function status(): AiRequestStatus
    {
        return AiRequestStatus::Unavailable;
    }

    public function userMessage(): string
    {
        return "Unable to connect to Ollama at {$this->endpoint}. Please verify that Ollama is running.";
    }

    public function hint(): string
    {
        return 'Start Ollama (it runs in the background once launched), then try again. '
            .'If it is not installed yet, install it from ollama.com and pull a model.';
    }
}
