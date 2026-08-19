<?php

declare(strict_types=1);

namespace App\Services\Email\Exceptions;

use RuntimeException;

/**
 * Raised when the allowlist is on and the recipient is not on it.
 *
 * Thrown by the transport rather than checked only in a controller, for the
 * same reason as EmailNotApprovedException: the rail has to hold even when the
 * caller forgot it exists.
 */
class RecipientNotAllowedException extends RuntimeException
{
    public function __construct(
        public readonly string $recipient,
        private readonly string $reason,
    ) {
        parent::__construct("Refusing to send to {$recipient}: not on the recipient allowlist.");
    }

    public function userMessage(): string
    {
        return $this->reason;
    }
}
