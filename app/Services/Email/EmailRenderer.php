<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailDraft;

/**
 * Composes what the recipient would actually see.
 *
 * The signature is appended HERE, by the application, from the settings the
 * user configured. The model never writes it - EmailValidator strips any
 * sign-off block the model produced - because a model asked to sign off invents
 * job titles and phone numbers, and a wrong phone number in outreach sent in
 * your name is a real problem.
 *
 * Equally important is what this does NOT include. No confidence score, no
 * reasoning, no product matching, no prompt, no model name, no internal notes.
 * Those exist on the draft and the generation, and they stay on this side of
 * the boundary: the preview shows exactly what would be sent and nothing else.
 *
 * Plain text only. Nothing here emits markup.
 */
class EmailRenderer
{
    public function __construct(
        private readonly EmailSettings $settings,
    ) {}

    /**
     * The complete email, ready to read or to hand to a transport.
     *
     * @return array{
     *     from: string|null,
     *     from_name: string|null,
     *     to: string|null,
     *     to_name: string|null,
     *     subject: string,
     *     body: string,
     *     signature: string,
     *     body_without_signature: string
     * }
     */
    public function render(EmailDraft $draft): array
    {
        $draft->loadMissing('contact');

        $body = trim((string) $draft->body);
        $signature = $this->settings->signature();

        return [
            'from' => $this->settings->senderEmail(),
            'from_name' => $this->settings->senderName(),
            'to' => $draft->recipient_email ?? $draft->contact?->email,
            'to_name' => $draft->recipient_name ?? $draft->contact?->full_name,
            'subject' => trim((string) $draft->subject),
            'body' => $this->withSignature($body, $signature),
            'signature' => $signature,
            'body_without_signature' => $body,
        ];
    }

    /**
     * Append the signature block.
     *
     * A blank signature appends nothing rather than trailing whitespace, so an
     * unconfigured profile produces a plain unsigned email instead of one that
     * looks like it lost something.
     */
    public function withSignature(string $body, ?string $signature = null): string
    {
        $signature = trim((string) ($signature ?? $this->settings->signature()));
        $body = rtrim($body);

        if ($signature === '') {
            return $body;
        }

        return $body."\n\n".$signature;
    }
}
