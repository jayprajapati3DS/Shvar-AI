<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailLength;
use App\Enums\EmailTone;
use App\Models\EmailSetting;

/**
 * The local sender profile: who the emails are from, and how they should read.
 *
 * The signature is deliberately NOT something the model writes. It is appended
 * by the application at render time from the fields below, because a model
 * asked to sign off invents job titles and phone numbers - and a wrong phone
 * number in an outreach email is a real problem, not a cosmetic one.
 *
 * Mirrors AiSettings in shape (whitelist, fallback, one query per request) but
 * is a separate store: that class guards which endpoint AI traffic may reach,
 * and mixing signature fields into its whitelist would blur what it protects.
 */
class EmailSettings
{
    /** The only keys that may be written. */
    public const WRITABLE_KEYS = [
        'sender_name',
        'sender_job_title',
        'sender_company',
        'sender_email',
        'sender_phone',
        'sender_website',
        'sender_linkedin',
        'signature',
        'tone',
        'length',
        'learning_enabled',
    ];

    /** The fields that make up the auto-composed signature block. */
    private const SIGNATURE_FIELDS = [
        'sender_name',
        'sender_job_title',
        'sender_company',
        'sender_email',
        'sender_phone',
        'sender_website',
    ];

    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    /* ---------------------------------------------------------------------- */
    /* Read */
    /* ---------------------------------------------------------------------- */

    public function senderName(): ?string
    {
        return $this->stored('sender_name');
    }

    public function senderJobTitle(): ?string
    {
        return $this->stored('sender_job_title');
    }

    public function senderCompany(): ?string
    {
        return $this->stored('sender_company');
    }

    public function senderEmail(): ?string
    {
        return $this->stored('sender_email');
    }

    public function senderPhone(): ?string
    {
        return $this->stored('sender_phone');
    }

    public function senderWebsite(): ?string
    {
        return $this->stored('sender_website');
    }

    public function senderLinkedin(): ?string
    {
        return $this->stored('sender_linkedin');
    }

    public function tone(): EmailTone
    {
        return EmailTone::tryFrom((string) $this->stored('tone')) ?? EmailTone::Professional;
    }

    public function length(): EmailLength
    {
        return EmailLength::tryFrom((string) $this->stored('length')) ?? EmailLength::Standard;
    }

    /**
     * Whether generations learn from the emails you approve.
     *
     * On by default: the signal is already there and throwing it away makes
     * every email start from the same blank slate. EmailStyleProfile shows
     * exactly what it concluded, so this is a switch you can make an informed
     * decision about rather than a black box you either trust or do not.
     */
    public function learningEnabled(): bool
    {
        $stored = $this->stored('learning_enabled');

        return $stored === null ? true : $stored === '1';
    }

    /**
     * The signature block appended to every email.
     *
     * A hand-written override wins outright. Otherwise one is composed from the
     * profile fields, skipping any that are blank so an incomplete profile never
     * produces a line with a label and nothing after it.
     */
    public function signature(): string
    {
        $custom = $this->stored('signature');

        if ($custom !== null && trim($custom) !== '') {
            return trim($custom);
        }

        $lines = [];

        foreach (self::SIGNATURE_FIELDS as $key) {
            $value = $this->stored($key);

            if ($value !== null && trim($value) !== '') {
                $lines[] = trim($value);
            }
        }

        return implode("\n", $lines);
    }

    public function isUsingComposedSignature(): bool
    {
        $custom = $this->stored('signature');

        return $custom === null || trim($custom) === '';
    }

    /**
     * Whether enough of a profile exists to sign an email at all.
     *
     * Only the name is genuinely required - an email signed with nothing looks
     * broken in a way a missing phone number does not.
     */
    public function isConfigured(): bool
    {
        return $this->signature() !== '';
    }

    /**
     * Fields worth filling in, for the nudge on the settings page.
     *
     * @return list<string>
     */
    public function gaps(): array
    {
        $labels = [
            'sender_name' => 'Your name',
            'sender_job_title' => 'Your job title',
            'sender_company' => 'Your company',
            'sender_email' => 'Your email address',
        ];

        $missing = [];

        foreach ($labels as $key => $label) {
            if (blank($this->stored($key))) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /* ---------------------------------------------------------------------- */
    /* Write */
    /* ---------------------------------------------------------------------- */

    /**
     * Persist settings locally. Keys outside WRITABLE_KEYS are ignored rather
     * than trusted.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::WRITABLE_KEYS, true)) {
                continue;
            }

            $normalised = match ($key) {
                // An unrecognised tone or length falls back to the default
                // rather than being stored and silently breaking generation.
                'tone' => (EmailTone::tryFrom((string) $value) ?? EmailTone::Professional)->value,
                'length' => (EmailLength::tryFrom((string) $value) ?? EmailLength::Standard)->value,
                'learning_enabled' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                default => $value === null || trim((string) $value) === '' ? null : trim((string) $value),
            };

            EmailSetting::updateOrCreate(['key' => $key], ['value' => $normalised]);
        }

        $this->cache = null;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Everything the settings screen and the generator need.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sender_name' => $this->senderName(),
            'sender_job_title' => $this->senderJobTitle(),
            'sender_company' => $this->senderCompany(),
            'sender_email' => $this->senderEmail(),
            'sender_phone' => $this->senderPhone(),
            'sender_website' => $this->senderWebsite(),
            'sender_linkedin' => $this->senderLinkedin(),

            'signature' => $this->signature(),
            'custom_signature' => $this->stored('signature'),
            'using_composed_signature' => $this->isUsingComposedSignature(),
            'configured' => $this->isConfigured(),
            'gaps' => $this->gaps(),

            'tone' => $this->tone()->value,
            'length' => $this->length()->value,
            'learning_enabled' => $this->learningEnabled(),
        ];
    }

    private function stored(string $key): ?string
    {
        // One query per request, not one per getter.
        $this->cache ??= EmailSetting::allValues();

        $value = $this->cache[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }
}
