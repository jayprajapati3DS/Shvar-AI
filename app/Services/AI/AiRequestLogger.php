<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Models\AiRequest;
use App\Services\AI\Exceptions\AiException;
use Throwable;

/**
 * Writes AI attempts to the local ai_requests table.
 *
 * PRIVACY: this is the only writer, and it writes to the local SQLite file and
 * nowhere else. There is no remote sink, no queue, no log shipper.
 *
 * Separate from OllamaAIService so the transport does not also own persistence,
 * and so a test can assert on logging without stubbing HTTP.
 *
 * Logging must never break a working AI call: every write is wrapped, and a
 * failure to log is swallowed after being reported to the application log.
 */
class AiRequestLogger
{
    public function __construct(
        private readonly AiSettings $settings,
    ) {}

    /** Records a successful completion. Returns the row id, or null. */
    public function logSuccess(
        string $provider,
        string $model,
        AiRequestType $type,
        string $prompt,
        string $response,
        int $executionMs,
        bool $structured = false,
        ?string $template = null,
        ?int $promptTokens = null,
        ?int $responseTokens = null,
        ?float $temperature = null,
        ?int $leadId = null,
        ?int $companyId = null,
    ): ?int {
        return $this->write([
            'provider' => $provider,
            'model' => $model,
            'request_type' => $type,
            'lead_id' => $leadId,
            'company_id' => $companyId,
            'status' => AiRequestStatus::Success,
            'prompt' => $this->cap($prompt),
            'response' => $this->cap($response),
            'execution_time_ms' => $executionMs,
            'structured' => $structured,
            'template' => $template,
            'prompt_tokens' => $promptTokens,
            'response_tokens' => $responseTokens,
            'temperature' => $temperature,
        ]);
    }

    /**
     * Records a failure.
     *
     * `error_message` holds the technical detail for diagnosis. The UI shows the
     * exception's userMessage() instead, so this text never reaches the browser
     * except on the log detail page the user explicitly opens.
     */
    public function logFailure(
        string $provider,
        string $model,
        AiRequestType $type,
        string $prompt,
        Throwable $exception,
        int $executionMs,
        bool $structured = false,
        ?string $template = null,
        ?float $temperature = null,
        ?int $leadId = null,
        ?int $companyId = null,
    ): ?int {
        return $this->write([
            'provider' => $provider,
            'model' => $model,
            'request_type' => $type,
            'lead_id' => $leadId,
            'company_id' => $companyId,
            'status' => $exception instanceof AiException
                ? $exception->status()
                : AiRequestStatus::Failed,
            'prompt' => $this->cap($prompt),
            'response' => null,
            'execution_time_ms' => $executionMs,
            'error_message' => $this->cap($exception->getMessage()),
            'structured' => $structured,
            'template' => $template,
            'temperature' => $temperature,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function write(array $attributes): ?int
    {
        if (! $this->settings->loggingEnabled()) {
            return null;
        }

        try {
            return AiRequest::create($attributes)->id;
        } catch (Throwable $e) {
            // A log failure must not take down a working AI response.
            report($e);

            return null;
        }
    }

    /**
     * Caps stored text so a runaway loop cannot grow the local database without
     * bound. The truncation is marked so a short row is never mistaken for the
     * model's actual full answer.
     */
    private function cap(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $limit = $this->settings->maxStoredChars();

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit)."\n\n… [truncated for local storage at {$limit} characters]";
    }
}
