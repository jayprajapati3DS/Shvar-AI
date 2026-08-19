<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use App\Enums\AiRequestStatus;

/** The model did not finish within the configured timeout. */
class AiTimeoutException extends AiException
{
    public function __construct(
        private readonly int $seconds,
        private readonly string $model = '',
    ) {
        parent::__construct("Request to [{$model}] exceeded {$seconds}s timeout");
    }

    public function status(): AiRequestStatus
    {
        return AiRequestStatus::Timeout;
    }

    public function userMessage(): string
    {
        return "The local model did not respond within {$this->seconds} seconds.";
    }

    public function hint(): string
    {
        return 'A first request is slower because the model has to load into memory. '
            .'Try again, raise the timeout in Settings, or use a smaller model.';
    }
}
