<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\EmailSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * SMTP connection settings, editable from Settings -> Email.
 *
 * Shares the email_settings table with EmailSettings but is a separate class
 * because it holds a different kind of thing: EmailSettings is your name and
 * how you like emails written, this is a credential that can send mail as you.
 *
 * TWO RULES THIS CLASS EXISTS TO ENFORCE:
 *
 *   1. The password is encrypted at rest with APP_KEY. It is the only value in
 *      this application that is not stored as plain text, because it is the
 *      only one that is a secret rather than data you typed.
 *
 *   2. The password NEVER goes back to the browser. toArray() reports whether
 *      one is set, never what it is - so an unlocked laptop showing the
 *      settings page does not show a mail password, and it cannot leak through
 *      an Inertia payload, a page cache or a screenshot.
 *
 * The recipient allowlist is deliberately NOT here. It is a safety rail, and a
 * rail you can remove by clicking is not much of one - it lives in config/env,
 * like OLLAMA_URL, and this page renders it read-only.
 */
class SmtpSettings
{
    /** Everything the settings form may write. */
    public const WRITABLE_KEYS = [
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_from_address',
        'smtp_from_name',
    ];

    /** Transport encryption modes offered. */
    public const ENCRYPTION_MODES = ['tls', 'ssl', 'none'];

    /** Sent by the form when the user did not retype the password. */
    public const UNCHANGED = '__unchanged__';

    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    /* ---------------------------------------------------------------------- */
    /* Read */
    /* ---------------------------------------------------------------------- */

    public function host(): ?string
    {
        return $this->stored('smtp_host');
    }

    public function port(): int
    {
        $port = (int) ($this->stored('smtp_port') ?? 0);

        // 587 (STARTTLS submission) is what Microsoft 365 and almost everything
        // else expects. 25 would be a worse guess - it is usually blocked.
        return $port > 0 ? $port : 587;
    }

    public function encryption(): string
    {
        $mode = (string) ($this->stored('smtp_encryption') ?? 'tls');

        return in_array($mode, self::ENCRYPTION_MODES, true) ? $mode : 'tls';
    }

    public function username(): ?string
    {
        return $this->stored('smtp_username');
    }

    /**
     * The decrypted password.
     *
     * Only SmtpEmailService should ever call this. A value that fails to
     * decrypt returns null rather than throwing: that means APP_KEY changed, and
     * the right outcome is "SMTP is not configured, set it again" rather than a
     * 500 on the settings page.
     */
    public function password(): ?string
    {
        $stored = $this->stored('smtp_password');

        if ($stored === null) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasPassword(): bool
    {
        return $this->password() !== null;
    }

    /** The envelope From. Falls back to the sender profile's address. */
    public function fromAddress(?string $fallback = null): ?string
    {
        return $this->stored('smtp_from_address') ?? $fallback;
    }

    public function fromName(?string $fallback = null): ?string
    {
        return $this->stored('smtp_from_name') ?? $fallback;
    }

    /** Whether there is enough here to attempt a connection. */
    public function isConfigured(): bool
    {
        return filled($this->host())
            && filled($this->username())
            && $this->hasPassword();
    }

    /**
     * What is still missing, for the settings page.
     *
     * @return list<string>
     */
    public function gaps(): array
    {
        $missing = [];

        if (blank($this->host())) {
            $missing[] = 'SMTP host';
        }

        if (blank($this->username())) {
            $missing[] = 'Username';
        }

        if (! $this->hasPassword()) {
            $missing[] = 'Password';
        }

        return $missing;
    }

    /* ---------------------------------------------------------------------- */
    /* Write */
    /* ---------------------------------------------------------------------- */

    /**
     * Persist settings. Keys outside WRITABLE_KEYS are ignored rather than
     * trusted.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::WRITABLE_KEYS, true)) {
                continue;
            }

            if ($key === 'smtp_password') {
                $this->savePassword($value);

                continue;
            }

            $normalised = match ($key) {
                'smtp_port' => $value === null || $value === '' ? null : (string) max(1, (int) $value),
                'smtp_encryption' => in_array((string) $value, self::ENCRYPTION_MODES, true)
                    ? (string) $value
                    : 'tls',
                default => $value === null || trim((string) $value) === '' ? null : trim((string) $value),
            };

            EmailSetting::updateOrCreate(['key' => $key], ['value' => $normalised]);
        }

        $this->cache = null;
    }

    /**
     * Store the password, encrypted.
     *
     * The UNCHANGED sentinel is how the form says "I did not retype it" - the
     * page never receives the real password, so it cannot submit it back, and
     * an empty string would otherwise read as "clear it" on every save.
     */
    private function savePassword(mixed $value): void
    {
        $value = is_string($value) ? $value : null;

        if ($value === self::UNCHANGED) {
            return;
        }

        if ($value === null || $value === '') {
            EmailSetting::where('key', 'smtp_password')->delete();

            return;
        }

        EmailSetting::updateOrCreate(
            ['key' => 'smtp_password'],
            ['value' => Crypt::encryptString($value)],
        );
    }

    /** Remove every SMTP setting, password included. */
    public function forget(): void
    {
        EmailSetting::whereIn('key', self::WRITABLE_KEYS)->delete();

        $this->cache = null;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * What the settings screen may see.
     *
     * Note what is absent: the password. `password_set` is a boolean, and that
     * is the only thing the browser is ever told about it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => $this->encryption(),
            'username' => $this->username(),
            'password_set' => $this->hasPassword(),
            'from_address' => $this->stored('smtp_from_address'),
            'from_name' => $this->stored('smtp_from_name'),
            'configured' => $this->isConfigured(),
            'gaps' => $this->gaps(),
            'encryption_modes' => self::ENCRYPTION_MODES,
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
