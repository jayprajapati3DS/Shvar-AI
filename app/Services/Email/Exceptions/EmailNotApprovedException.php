<?php

declare(strict_types=1);

namespace App\Services\Email\Exceptions;

use App\Enums\EmailDraftStatus;
use RuntimeException;

/**
 * Raised when something tries to send a draft a human has not approved.
 *
 * This is the backstop behind the single most important rule in Phase 4. The
 * controller checks the status, the UI hides the button, and the enum answers
 * isSendable() - but the transport itself refuses too, so a future
 * implementation added by someone who has not read any of that still cannot
 * send an unapproved email.
 */
class EmailNotApprovedException extends RuntimeException
{
    public function __construct(
        public readonly EmailDraftStatus $status,
    ) {
        parent::__construct(sprintf(
            'Refusing to send a draft with status "%s". Only an approved email may be sent.',
            $status->value,
        ));
    }

    public function userMessage(): string
    {
        return 'This email has not been approved yet. Approve it first, then send.';
    }
}
