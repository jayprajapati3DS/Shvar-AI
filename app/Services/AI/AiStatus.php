<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * A snapshot of the local AI backend, for the Settings screen.
 *
 * Answers two separate questions that the UI shows as separate indicators:
 * is the runtime reachable, and is the configured model actually installed.
 * A reachable Ollama with no models pulled is a real and common state.
 */
final readonly class AiStatus
{
    /** @param list<string> $installedModels */
    public function __construct(
        public bool $connected,
        public bool $modelInstalled,
        public string $provider,
        public string $endpoint,
        public string $model,
        public array $installedModels = [],
        public ?string $version = null,
        public ?int $probeMs = null,
        /** User-safe reason when not connected. Never a stack trace. */
        public ?string $message = null,
        public ?string $hint = null,
    ) {}

    /** Everything needed to actually run a request. */
    public function isReady(): bool
    {
        return $this->connected && $this->modelInstalled;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'connected' => $this->connected,
            'model_installed' => $this->modelInstalled,
            'ready' => $this->isReady(),
            'provider' => $this->provider,
            'endpoint' => $this->endpoint,
            'model' => $this->model,
            'installed_models' => $this->installedModels,
            'version' => $this->version,
            'probe_ms' => $this->probeMs,
            'message' => $this->message,
            'hint' => $this->hint,
        ];
    }
}
