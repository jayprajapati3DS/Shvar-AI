<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailDraft;
use App\Services\Email\Exceptions\EmailNotApprovedException;

/**
 * The seam between Shvar AI Copilot and however an email eventually leaves.
 *
 *   Shvar AI Copilot
 *        v
 *   EmailServiceInterface        <- callers depend only on this
 *        v
 *   LocalTestEmailService        <- the ONLY implementation in Phase 4
 *
 * Future phases may add SmtpEmailService, GmailEmailService or
 * MicrosoftGraphEmailService behind this same interface. None exist yet, and
 * nothing in Phase 4 opens a socket: LocalTestEmailService writes a row and
 * returns.
 *
 * NON-NEGOTIABLE, and the reason this interface is so small: an implementation
 * may only ever be called with a draft the user has explicitly approved.
 * send() asserts that itself rather than trusting the caller, so adding a real
 * transport later cannot accidentally introduce a path that sends a draft.
 */
interface EmailServiceInterface
{
    /**
     * Deliver an approved draft.
     *
     * @throws EmailNotApprovedException
     *                                   when the draft has not been approved by a human.
     */
    public function send(EmailDraft $draft): EmailSendResult;

    /**
     * How this implementation delivers, stored on the draft as delivery_mode.
     *
     * 'simulated' is the only value Phase 4 can produce, so a simulated send can
     * never be mistaken for a real one when reading the record back.
     */
    public function mode(): string;

    /** Whether anything actually leaves this machine. False for Phase 4. */
    public function isSimulated(): bool;

    /** Short human description for the confirmation dialog. */
    public function description(): string;
}
