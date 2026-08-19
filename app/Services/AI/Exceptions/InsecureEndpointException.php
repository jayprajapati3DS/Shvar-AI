<?php

declare(strict_types=1);

namespace App\Services\AI\Exceptions;

use App\Enums\AiRequestStatus;

/**
 * The configured endpoint is not a local host.
 *
 * This is a hard stop, not a warning. The entire privacy premise of the app is
 * that AI requests never leave the machine, so a misconfigured OLLAMA_URL must
 * fail loudly rather than quietly forward CRM data to a third party.
 */
class InsecureEndpointException extends AiException
{
    /** @param list<string> $allowed */
    public function __construct(
        private readonly string $host,
        private readonly array $allowed,
    ) {
        parent::__construct(
            "Refusing to use non-local AI endpoint host [{$host}]. Allowed: ".implode(', ', $allowed)
        );
    }

    public function status(): AiRequestStatus
    {
        return AiRequestStatus::Rejected;
    }

    public function userMessage(): string
    {
        return 'The configured AI endpoint is not on this machine, so the request was blocked.';
    }

    public function hint(): string
    {
        return 'OLLAMA_URL in .env must point at a local address such as http://localhost:11434.';
    }
}
