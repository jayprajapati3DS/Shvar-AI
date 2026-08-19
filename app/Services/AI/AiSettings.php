<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiSetting;

/**
 * Resolves effective AI settings: locally saved value, else config/ai.php.
 *
 * SECURITY BOUNDARY. The endpoint is not in WRITABLE_KEYS and there is no
 * setter for it. It is read from config (i.e. .env) only, so no request from the
 * browser can repoint AI traffic at a remote host. That is the whole reason this
 * class exists rather than callers reading config() directly - there is exactly
 * one place where "which URL do we call" is answered.
 */
class AiSettings
{
    /**
     * The only keys a user may change from the UI.
     *
     * Deliberately excludes: url, provider, allowed_hosts, limits.
     */
    public const WRITABLE_KEYS = [
        'model',
        'temperature',
        'timeout',
        'max_tokens',
        'system_prompt',
    ];

    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    /* ---------------------------------------------------------------------- */
    /* Read */
    /* ---------------------------------------------------------------------- */

    public function provider(): string
    {
        // Config only. Not user-writable.
        return (string) config('ai.provider', 'ollama');
    }

    /**
     * The local endpoint.
     *
     * Config only - see the class docblock. Never sourced from the database.
     */
    public function endpoint(): string
    {
        return rtrim((string) config('ai.ollama.url', 'http://localhost:11434'), '/');
    }

    public function model(): string
    {
        $saved = $this->stored('model');

        return $saved !== null && $saved !== ''
            ? $saved
            : (string) config('ai.ollama.model');
    }

    public function temperature(): float
    {
        $saved = $this->stored('temperature');

        $value = $saved !== null && $saved !== ''
            ? (float) $saved
            : (float) config('ai.defaults.temperature', 0.7);

        return $this->clampTemperature($value);
    }

    public function timeout(): int
    {
        $saved = $this->stored('timeout');

        $value = $saved !== null && $saved !== ''
            ? (int) $saved
            : (int) config('ai.defaults.timeout', 120);

        return $this->clampTimeout($value);
    }

    public function probeTimeout(): int
    {
        return (int) config('ai.defaults.probe_timeout', 5);
    }

    public function maxTokens(): ?int
    {
        $saved = $this->stored('max_tokens');

        if ($saved !== null && $saved !== '') {
            return max(1, (int) $saved);
        }

        $default = config('ai.defaults.max_tokens');

        return $default === null ? null : (int) $default;
    }

    /** The active system prompt: local override, else the library base. */
    public function systemPrompt(): string
    {
        $saved = $this->stored('system_prompt');

        return $saved !== null && trim($saved) !== ''
            ? $saved
            : PromptLibrary::BASE_SYSTEM_PROMPT;
    }

    public function isUsingDefaultSystemPrompt(): bool
    {
        $saved = $this->stored('system_prompt');

        return $saved === null || trim($saved) === '';
    }

    public function loggingEnabled(): bool
    {
        return (bool) config('ai.logging.enabled', true);
    }

    public function maxPromptChars(): int
    {
        return (int) config('ai.limits.max_prompt_chars', 20000);
    }

    public function maxResponseChars(): int
    {
        return (int) config('ai.limits.max_response_chars', 100000);
    }

    public function maxStoredChars(): int
    {
        return (int) config('ai.logging.max_stored_chars', 20000);
    }

    /* ---------------------------------------------------------------------- */
    /* Write */
    /* ---------------------------------------------------------------------- */

    /**
     * Persist settings locally. Keys outside WRITABLE_KEYS are ignored rather
     * than trusted, so a crafted request cannot smuggle in an endpoint override.
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
                'temperature' => (string) $this->clampTemperature((float) $value),
                'timeout' => (string) $this->clampTimeout((int) $value),
                'max_tokens' => $value === null || $value === '' ? null : (string) max(1, (int) $value),
                default => $value === null ? null : (string) $value,
            };

            AiSetting::updateOrCreate(['key' => $key], ['value' => $normalised]);
        }

        $this->cache = null;
    }

    /** Drop a local override so the config/ai.php value applies again. */
    public function forget(string $key): void
    {
        if (! in_array($key, self::WRITABLE_KEYS, true)) {
            return;
        }

        AiSetting::where('key', $key)->delete();
        $this->cache = null;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Everything the Settings screen needs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider(),
            'endpoint' => $this->endpoint(),
            'model' => $this->model(),
            'temperature' => $this->temperature(),
            'timeout' => $this->timeout(),
            'max_tokens' => $this->maxTokens(),
            'system_prompt' => $this->systemPrompt(),
            'using_default_system_prompt' => $this->isUsingDefaultSystemPrompt(),
            'logging_enabled' => $this->loggingEnabled(),

            // Rendered as read-only fields / validation hints in the UI.
            'endpoint_is_read_only' => true,
            'limits' => [
                'max_prompt_chars' => $this->maxPromptChars(),
                'min_temperature' => (float) config('ai.limits.min_temperature', 0.0),
                'max_temperature' => (float) config('ai.limits.max_temperature', 2.0),
                'min_timeout' => (int) config('ai.limits.min_timeout', 5),
                'max_timeout' => (int) config('ai.limits.max_timeout', 600),
            ],
        ];
    }

    private function stored(string $key): ?string
    {
        // One query per request, not one per getter.
        $this->cache ??= AiSetting::allValues();

        return $this->cache[$key] ?? null;
    }

    private function clampTemperature(float $value): float
    {
        return max(
            (float) config('ai.limits.min_temperature', 0.0),
            min((float) config('ai.limits.max_temperature', 2.0), $value),
        );
    }

    private function clampTimeout(int $value): int
    {
        return max(
            (int) config('ai.limits.min_timeout', 5),
            min((int) config('ai.limits.max_timeout', 600), $value),
        );
    }
}
