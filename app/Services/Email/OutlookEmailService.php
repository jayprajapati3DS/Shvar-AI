<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailDraft;
use App\Services\Email\Exceptions\EmailNotApprovedException;
use App\Services\Email\Exceptions\RecipientNotAllowedException;
use App\Services\Email\Outlook\OutlookGatewayInterface;
use DateTimeImmutable;
use RuntimeException;

/**
 * Hands an approved draft to the Outlook desktop app.
 *
 * Unlike SmtpEmailService, this does NOT send. It creates the message in
 * Outlook, fully populated, and opens the compose window. You press Send.
 *
 * That is a deliberate choice and it changes what "sent" means here:
 *
 *   Approved  --hand to Outlook-->  Queued  --you press Send in Outlook-->  Sent
 *
 * The draft becomes Queued, not Sent, because at that moment nothing has been
 * sent - it is sitting in a window on your screen. Claiming otherwise would put
 * a lie in the activity timeline. OutlookReconciler moves it to Sent once the
 * message actually appears as sent, which is the only honest signal available.
 *
 * Queued has existed on EmailDraftStatus since Phase 4 with nothing setting it.
 * This is what it was for.
 *
 * The same three gates as SMTP still apply, checked here rather than trusted
 * from the caller: approved, allowlisted, reachable.
 */
class OutlookEmailService implements EmailServiceInterface
{
    public const MODE = 'outlook';

    public function __construct(
        private readonly EmailRenderer $renderer,
        private readonly OutlookGatewayInterface $outlook,
        private readonly RecipientAllowlist $allowlist,
    ) {}

    public function send(EmailDraft $draft): EmailSendResult
    {
        // Gate 1: human approval.
        if (! $draft->isSendable()) {
            throw new EmailNotApprovedException($draft->status);
        }

        $draft->loadMissing(['contact', 'product', 'lead.company']);

        $rendered = $this->renderer->render($draft);
        $recipient = (string) ($draft->recipient_email ?? $draft->contact?->email ?? '');

        if ($recipient === '') {
            throw new RuntimeException('This draft has no recipient address.');
        }

        // Gate 2: the safety rail. It applies here too - the message would be
        // sitting in an Outlook window addressed to a real person, one keystroke
        // from going out.
        if (! $this->allowlist->permits($recipient)) {
            throw new RecipientNotAllowedException(
                $recipient,
                $this->allowlist->refusalReason($recipient),
            );
        }

        // Gate 3: Outlook is actually there.
        if (! $this->outlook->isAvailable()) {
            throw new RuntimeException(
                'Outlook did not respond. Make sure the classic Outlook desktop app is running.'
            );
        }

        $entryId = $this->outlook->displayDraft([
            'to' => $recipient,
            'to_name' => $rendered['to_name'],
            'subject' => (string) $rendered['subject'],
            'body' => (string) $rendered['body'],
        ]);

        return new EmailSendResult(
            delivered: false,
            mode: self::MODE,
            recipient: $recipient,
            subject: (string) $rendered['subject'],
            at: new DateTimeImmutable,
            simulated: false,
            error: null,
            handedOff: true,
            reference: $entryId,
        );
    }

    public function mode(): string
    {
        return self::MODE;
    }

    /**
     * False: this is real mail, in the real Outlook, addressed to a real person.
     *
     * Nothing has left yet, but calling it simulated would be wrong - a
     * simulated send cannot be completed by pressing a button.
     */
    public function isSimulated(): bool
    {
        return false;
    }

    public function description(): string
    {
        $status = $this->outlook->status();

        $who = $status['available'] && $status['account'] !== null
            ? " Signed in as {$status['account']}."
            : ' Outlook is not responding at the moment.';

        $rail = $this->allowlist->isRestricting()
            ? ' The recipient allowlist is ON: only '
                .implode(', ', $this->allowlist->entries()).' can be addressed.'
            : '';

        return 'Opens the message in your Outlook desktop app for a final look. You press Send '
            .'there, so nothing goes out from here.'.$who.$rail;
    }
}
