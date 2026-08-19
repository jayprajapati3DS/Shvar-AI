<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailDraft;
use App\Services\Email\Exceptions\EmailNotApprovedException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The only email service Phase 4 ships.
 *
 * It simulates delivery. Nothing opens a socket, resolves a mail host, or
 * touches an external service - the method records what WOULD have been sent to
 * the local application log and returns. That is the whole point: the approval
 * workflow can be exercised end to end without a real person receiving a test
 * email from a half-built CRM.
 *
 * Anything reading a record back can tell: delivery_mode is 'simulated', and
 * the result reports simulated: true. A future real transport would set its own
 * mode, so the two can never be confused.
 */
class LocalTestEmailService implements EmailServiceInterface
{
    public const MODE = 'simulated';

    public function __construct(
        private readonly EmailRenderer $renderer,
    ) {}

    /**
     * "Send" an approved draft.
     *
     * The approval check is here rather than only in the controller on purpose -
     * see EmailNotApprovedException. It is the last gate, and it does not trust
     * whoever called it.
     */
    public function send(EmailDraft $draft): EmailSendResult
    {
        if (! $draft->isSendable()) {
            throw new EmailNotApprovedException($draft->status);
        }

        $draft->loadMissing(['contact', 'product', 'lead.company']);

        $recipient = (string) ($draft->recipient_email ?? $draft->contact?->email ?? '');
        $rendered = $this->renderer->render($draft);
        $at = new DateTimeImmutable;

        // The local application log, which stays on this machine like every
        // other log in this project. Body included because the point of a
        // simulated send is being able to read exactly what would have gone.
        Log::info('Simulated email send', [
            'email_draft_id' => $draft->id,
            'recipient' => $recipient,
            'subject' => $draft->subject,
            'body' => $rendered['body'],
            'timestamp' => $at->format(DATE_ATOM),
            'delivery_mode' => self::MODE,
        ]);

        return new EmailSendResult(
            delivered: true,
            mode: self::MODE,
            recipient: $recipient,
            subject: (string) $draft->subject,
            at: $at,
            simulated: true,
        );
    }

    public function mode(): string
    {
        return self::MODE;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Simulated send. Nothing leaves this machine - the email is written to the local '
            .'application log so the workflow can be tested without contacting a real person.';
    }
}
