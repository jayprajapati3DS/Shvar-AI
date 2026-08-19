<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiRequestType;
use App\Services\AI\Exceptions\AiException;

/**
 * The seam between Shvar AI Copilot and a local language model.
 *
 *   Shvar AI Copilot
 *        v
 *   AIServiceInterface        <- callers depend only on this
 *        v
 *   OllamaAIService
 *        v
 *   Ollama localhost API
 *        v
 *   Local LLM
 *
 * Nothing outside app/Services/AI knows Ollama exists. Swapping the runtime, or
 * binding a fake in tests, is a one-line change in AppServiceProvider.
 *
 * NON-NEGOTIABLE: every implementation must talk to a local endpoint. No cloud
 * AI provider may ever be bound to this interface. LocalEndpointGuard enforces
 * this at call time and a test asserts it across the source tree.
 *
 * This replaces the Phase 1 placeholder App\Contracts\Ai\AiProvider, which was
 * declared but never implemented.
 */
interface AIServiceInterface
{
    /**
     * Run a prompt and return the completion as text.
     *
     * Implementations are responsible for logging the attempt - success or
     * failure - to the local ai_requests table before returning or throwing.
     *
     * @param  string|PromptTemplate  $prompt  Raw text, or a template to render.
     * @param  array{
     *     model?: string,
     *     temperature?: float,
     *     max_tokens?: int|null,
     *     timeout?: int,
     *     system?: string|null,
     *     think?: bool|null
     * }  $options  `think` suppresses a reasoning model's chain of thought.
     *              Leave unset for text (keeps the answer clean); structured
     *              requests force it false, because a reasoning model otherwise
     *              emits its JSON into a separate field and returns nothing.
     *
     * @throws AiException Always an AiException subclass, never a raw transport error.
     */
    public function generate(
        string|PromptTemplate $prompt,
        AiRequestType $type = AiRequestType::General,
        array $options = [],
    ): AiResult;

    /**
     * Run a prompt and return decoded structured data in AiResult::$data.
     *
     * Asks the model for JSON rather than hoping for it, and validates what
     * comes back. A response that is not usable JSON raises
     * InvalidAiResponseException instead of returning a half-parsed guess.
     *
     * @param  array<string, mixed>  $schema  Optional JSON Schema. Ollama constrains
     *                                        generation to it when supported; it is
     *                                        also used to check required keys.
     * @param  array<string, mixed>  $options As generate().
     *
     * @throws AiException
     */
    public function generateStructured(
        string|PromptTemplate $prompt,
        array $schema = [],
        AiRequestType $type = AiRequestType::General,
        array $options = [],
    ): AiResult;

    /**
     * Whether the local runtime is reachable right now.
     *
     * Never throws - returns false. Lets the UI show an honest "not connected"
     * state instead of an error page.
     */
    public function isAvailable(): bool;

    /**
     * Models installed locally, by name.
     *
     * Read from the runtime's own local API. Never triggers a download.
     * Returns an empty list if the runtime is unreachable.
     *
     * @return list<string>
     */
    public function getModels(): array;

    /** Whether a model (default: the configured one) is installed locally. */
    public function hasModel(?string $model = null): bool;

    /**
     * A full snapshot for the Settings screen: connection, model, versions.
     *
     * Never throws.
     */
    public function status(): AiStatus;

    /**
     * A minimal round trip used by the "Test AI Connection" button.
     *
     * @throws AiException
     */
    public function ping(): AiResult;

    /** Identifier of the provider, e.g. "ollama". */
    public function provider(): string;

    /** The model this service will use unless overridden per request. */
    public function model(): string;

    /** The local endpoint in use, for display. */
    public function endpoint(): string;
}
