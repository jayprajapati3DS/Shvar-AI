<?php

declare(strict_types=1);

namespace App\Services\Email;

/**
 * The safety rail on real sending.
 *
 * While the list is non-empty, SmtpEmailService will only deliver to addresses
 * on it. Everything else is refused with an explanation. Put your own address
 * on it, exercise the whole flow for real, then clear it to go live.
 *
 * It exists because of the specific accident this application makes possible:
 * the drafts are addressed to real people at real medical technology companies,
 * and the difference between testing the send path and contacting a prospect
 * with a half-finished email is one click. An empty list means no restriction,
 * so going live is a deliberate act rather than a default.
 *
 * CONFIG ONLY. There is no setter and it is not in any settings whitelist - it
 * is rendered read-only on the settings page. A rail you can remove by clicking
 * is not much of a rail; removing this one means opening .env.
 *
 * Matching is case-insensitive on the whole address. A leading "@" entry acts
 * as a domain rule, so "@3dsurgical.com" permits everyone in your own company -
 * which is the realistic way to test with colleagues.
 */
class RecipientAllowlist
{
    /**
     * The configured entries, normalised.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        $raw = config('email.allowed_recipients');

        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($entry) => mb_strtolower(trim((string) $entry)),
            $raw,
        ), fn (string $entry) => $entry !== ''));
    }

    /** Whether any restriction is in force. */
    public function isRestricting(): bool
    {
        return $this->entries() !== [];
    }

    /** Whether this address may be sent to. */
    public function permits(?string $email): bool
    {
        if (! $this->isRestricting()) {
            return true;
        }

        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        foreach ($this->entries() as $entry) {
            // "@example.com" permits the whole domain.
            if (str_starts_with($entry, '@')) {
                if (str_ends_with($email, $entry)) {
                    return true;
                }

                continue;
            }

            if ($entry === $email) {
                return true;
            }
        }

        return false;
    }

    /** Why an address was refused, for the user. */
    public function refusalReason(?string $email): string
    {
        return sprintf(
            'The recipient allowlist is on, and %s is not on it. Only %s can be emailed. '
            .'Edit EMAIL_ALLOWED_RECIPIENTS in .env to change that, or clear it to send to anyone.',
            $email === null || $email === '' ? 'that address' : $email,
            implode(', ', $this->entries()),
        );
    }

    /**
     * A description for the settings page.
     *
     * @return array{restricting: bool, entries: list<string>, summary: string}
     */
    public function describe(): array
    {
        $entries = $this->entries();

        return [
            'restricting' => $entries !== [],
            'entries' => $entries,
            'summary' => $entries === []
                ? 'No allowlist. An approved email will be sent to whoever it is addressed to.'
                : 'Only these addresses can receive email: '.implode(', ', $entries)
                    .'. Anything else is refused.',
        ];
    }
}
