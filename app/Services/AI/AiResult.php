<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiRequestType;

/**
 * The single return shape for every successful AI call.
 *
 * Immutable, and deliberately not an Eloquent model - it describes one
 * completion, whereas App\Models\AiRequest is the persisted log entry. Keeping
 * them separate means callers are not handed a database row they might save.
 */
final readonly class AiResult
{
    /**
     * @param  array<string, mixed>|null  $data  Decoded payload for a structured request; null otherwise.
     */
    public function __construct(
        public string $text,
        public ?array $data,
        public string $provider,
        public string $model,
        public AiRequestType $requestType,
        public int $executionMs,
        public bool $structured = false,
        public ?int $promptTokens = null,
        public ?int $responseTokens = null,
        public bool $truncated = false,
        /** The id of the ai_requests row, when logging is enabled. */
        public ?int $logId = null,
    ) {}

    public function seconds(): float
    {
        return round($this->executionMs / 1000, 2);
    }

    /** Value of one key from a structured response. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function withLogId(?int $logId): self
    {
        return new self(
            text: $this->text,
            data: $this->data,
            provider: $this->provider,
            model: $this->model,
            requestType: $this->requestType,
            executionMs: $this->executionMs,
            structured: $this->structured,
            promptTokens: $this->promptTokens,
            responseTokens: $this->responseTokens,
            truncated: $this->truncated,
            logId: $logId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'data' => $this->data,
            'provider' => $this->provider,
            'model' => $this->model,
            'request_type' => $this->requestType->value,
            'execution_ms' => $this->executionMs,
            'seconds' => $this->seconds(),
            'structured' => $this->structured,
            'prompt_tokens' => $this->promptTokens,
            'response_tokens' => $this->responseTokens,
            'truncated' => $this->truncated,
            'log_id' => $this->logId,
        ];
    }
}
