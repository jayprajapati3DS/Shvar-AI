<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailDraft;
use App\Services\Email\Exceptions\EmailNotApprovedException;
use App\Services\Email\Exceptions\RecipientNotAllowedException;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Sends a real email over SMTP.
 *
 * THIS IS THE FIRST THING IN THE APPLICATION THAT CONTACTS SOMEONE. Everything
 * before it produced local rows a human could read and delete. This does not.
 *
 * Three gates, in order, all inside send():
 *
 *   1. The draft must be Approved. Not "the controller checked" - checked here,
 *      because a transport that trusts its caller is one refactor away from
 *      sending a draft nobody signed off on.
 *   2. The recipient must pass RecipientAllowlist. While that list is non-empty
 *      only those addresses receive anything, so the flow can be exercised for
 *      real without a prospect getting a half-finished email.
 *   3. SMTP must actually be configured. A missing password fails loudly rather
 *      than silently attempting an anonymous relay.
 *
 * Driven through Symfony Mailer directly rather than Laravel's Mail facade, so
 * config/mail.php - stock scaffolding pointing at whatever MAIL_HOST happens to
 * say - is never involved. The only credentials this uses are the ones entered
 * in Settings, and the only recipient is the one on the draft.
 *
 * Plain text only. The body is what EmailRenderer produced; nothing here builds
 * HTML, so there is no markup for a mail client to interpret.
 */
class SmtpEmailService implements EmailServiceInterface
{
    public const MODE = 'smtp';

    public function __construct(
        private readonly EmailRenderer $renderer,
        private readonly SmtpSettings $settings,
        private readonly EmailSettings $profile,
        private readonly RecipientAllowlist $allowlist,
    ) {}

    public function send(EmailDraft $draft): EmailSendResult
    {
        // Gate 1: human approval.
        if (! $draft->isSendable()) {
            throw new EmailNotApprovedException($draft->status);
        }

        $draft->loadMissing(['product', 'lead.company']);

        $rendered = $this->renderer->render($draft);
        $recipient = (string) ($draft->recipient_email ?? $draft->lead?->email ?? '');

        if ($recipient === '') {
            throw new RuntimeException('This draft has no recipient address.');
        }

        // Gate 2: the safety rail.
        if (! $this->allowlist->permits($recipient)) {
            throw new RecipientNotAllowedException(
                $recipient,
                $this->allowlist->refusalReason($recipient),
            );
        }

        // Gate 3: real credentials.
        if (! $this->settings->isConfigured()) {
            throw new RuntimeException(
                'SMTP is not configured. Add the host, username and password in Settings -> Email.'
            );
        }

        $from = $this->settings->fromAddress($this->profile->senderEmail());

        if (blank($from)) {
            throw new RuntimeException(
                'No From address. Set one in Settings -> Email, on the SMTP section or your profile.'
            );
        }

        $message = (new MimeEmail)
            ->from(new Address(
                (string) $from,
                (string) ($this->settings->fromName($this->profile->senderName()) ?? ''),
            ))
            ->to(new Address($recipient, (string) ($rendered['to_name'] ?? '')))
            ->subject((string) $rendered['subject'])
            // text() not html(). Phase 4 produces plain text and this keeps it
            // that way - nothing the model wrote can render in a mail client.
            ->text((string) $rendered['body']);

        $at = new DateTimeImmutable;

        try {
            $this->transport()->send($message);
        } catch (TransportExceptionInterface $e) {
            // The technical detail is recorded on the draft by EmailDraftEditor;
            // this message is what the user sees.
            throw new RuntimeException(
                'The mail server rejected the message: '.$e->getMessage(),
                previous: $e,
            );
        }

        return new EmailSendResult(
            delivered: true,
            mode: self::MODE,
            recipient: $recipient,
            subject: (string) $rendered['subject'],
            at: $at,
            simulated: false,
        );
    }

    /**
     * Verify the connection without sending anything.
     *
     * Opens the transport, authenticates, and stops. The equivalent of "Test AI
     * Connection" for mail: it answers "are these credentials right" without
     * putting a message in anyone's inbox.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->settings->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Add the host, username and password first. Still missing: '
                    .implode(', ', $this->settings->gaps()).'.',
            ];
        }

        try {
            $transport = $this->transport();
            $transport->start();
            $transport->stop();
        } catch (TransportExceptionInterface $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'Connected to %s:%d as %s.',
                $this->settings->host(),
                $this->settings->port(),
                $this->settings->username(),
            ),
        ];
    }

    /**
     * Build the transport from the stored settings.
     *
     * Symfony's `$tls` constructor flag means IMPLICIT TLS - encrypted from the
     * first byte, which is port 465 only. It is deliberately false for our
     * 'tls' mode, because 587 wants STARTTLS: connect in the clear, then
     * upgrade. Symfony negotiates that automatically while autoTls is on, which
     * is exactly what Microsoft 365 expects.
     *
     * So the flag is NOT "do you want encryption". Only 'none' actually turns
     * encryption off, and it does so by disabling autoTls - the one switch that
     * stops the upgrade being attempted and stops Symfony refusing to
     * authenticate over a plaintext connection.
     */
    private function transport(): EsmtpTransport
    {
        $encryption = $this->settings->encryption();

        $transport = new EsmtpTransport(
            host: (string) $this->settings->host(),
            port: $this->settings->port(),
            tls: $encryption === 'ssl',
        );

        // 'none' exists for a local relay on a trusted network. Everything else
        // keeps autoTls on, so credentials are never sent over an unencrypted
        // connection by accident - Symfony throws rather than downgrading.
        if ($encryption === 'none') {
            $transport->setAutoTls(false);
        }

        $transport->setUsername((string) $this->settings->username());
        $transport->setPassword((string) $this->settings->password());

        return $transport;
    }

    public function mode(): string
    {
        return self::MODE;
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function description(): string
    {
        $host = $this->settings->host() ?? 'an unconfigured host';

        $rail = $this->allowlist->isRestricting()
            ? ' The recipient allowlist is ON: only '
                .implode(', ', $this->allowlist->entries()).' can receive anything.'
            : ' The allowlist is OFF - an approved email goes to whoever it is addressed to.';

        return "Real delivery over SMTP via {$host}. Messages leave this machine.".$rail;
    }
}
