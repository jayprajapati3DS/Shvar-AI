<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiRequestType;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Exceptions\AiTimeoutException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\Exceptions\InvalidAiResponseException;
use App\Services\AI\Exceptions\ModelUnavailableException;
use App\Services\AI\Exceptions\PromptTooLargeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Talks to Ollama over its local HTTP API.
 *
 *   Laravel  ->  this class  ->  http://localhost:11434  ->  local LLM
 *
 * The ONLY class in the application that knows Ollama exists. Everything else
 * depends on AIServiceInterface, so replacing the runtime touches nothing else.
 *
 * Every public method that can fail throws an AiException subclass carrying a
 * user-safe message - a raw ConnectionException or JSON error never escapes.
 * Every attempt, successful or not, is written to the local request log.
 *
 * Streaming: requests are sent with "stream": false and completions go through
 * the single private complete() method, so adding a streaming variant later
 * means one new method rather than reworking callers. Not built in Phase 2.
 */
class OllamaAIService implements AIServiceInterface
{
    private const PROVIDER = 'ollama';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly AiSettings $settings,
        private readonly AiRequestLogger $logger,
        private readonly StructuredResponseParser $parser,
        private readonly LocalEndpointGuard $guard,
        private readonly PromptLibrary $prompts,
    ) {}

    /* ---------------------------------------------------------------------- */
    /* Generation                                                             */
    /* ---------------------------------------------------------------------- */

    public function generate(
        string|PromptTemplate $prompt,
        AiRequestType $type = AiRequestType::General,
        array $options = [],
    ): AiResult {
        return $this->run($prompt, $type, $options, structured: false, schema: []);
    }

    public function generateStructured(
        string|PromptTemplate $prompt,
        array $schema = [],
        AiRequestType $type = AiRequestType::General,
        array $options = [],
    ): AiResult {
        return $this->run($prompt, $type, $options, structured: true, schema: $schema);
    }

    public function ping(): AiResult
    {
        // A structured round trip rather than a plain one: on a reasoning model
        // this is faster, returns a clean answer instead of the model's musings,
        // and proves the structured path works - which is what the AI features
        // built on this service will depend on.
        //
        // The timeout is generous because the first request after a restart also
        // pays to load the model into memory, which on CPU can take a while.
        return $this->generateStructured(
            $this->prompts->connectionTest(),
            $this->prompts->connectionTestSchema(),
            AiRequestType::General,
            [
                'timeout' => max(60, $this->settings->probeTimeout() * 6),
                'temperature' => 0.0,
            ],
        );
    }

    /* ---------------------------------------------------------------------- */
    /* Discovery                                                              */
    /* ---------------------------------------------------------------------- */

    public function isAvailable(): bool
    {
        try {
            return $this->tags() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public function getModels(): array
    {
        try {
            $payload = $this->tags();
        } catch (Throwable) {
            return [];
        }

        if ($payload === null) {
            return [];
        }

        $names = [];

        foreach ($payload['models'] ?? [] as $model) {
            $name = $model['name'] ?? $model['model'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    public function hasModel(?string $model = null): bool
    {
        $target = $model ?? $this->model();
        $installed = $this->getModels();

        if ($installed === []) {
            return false;
        }

        return $this->matchInstalled($target, $installed) !== null;
    }

    public function status(): AiStatus
    {
        $startedAt = hrtime(true);

        try {
            $this->guard->assertLocal($this->endpoint());
            $payload = $this->tags();
        } catch (AiException $e) {
            return new AiStatus(
                connected: false,
                modelInstalled: false,
                provider: self::PROVIDER,
                endpoint: $this->endpoint(),
                model: $this->model(),
                message: $e->userMessage(),
                hint: $e->hint(),
            );
        } catch (Throwable) {
            $payload = null;
        }

        $probeMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if ($payload === null) {
            $unavailable = new AiUnavailableException($this->endpoint());

            return new AiStatus(
                connected: false,
                modelInstalled: false,
                provider: self::PROVIDER,
                endpoint: $this->endpoint(),
                model: $this->model(),
                probeMs: $probeMs,
                message: $unavailable->userMessage(),
                hint: $unavailable->hint(),
            );
        }

        $installed = $this->getModels();
        $model = $this->model();
        $matched = $this->matchInstalled($model, $installed);

        return new AiStatus(
            connected: true,
            modelInstalled: $matched !== null,
            provider: self::PROVIDER,
            endpoint: $this->endpoint(),
            model: $model,
            installedModels: $installed,
            version: $this->version(),
            probeMs: $probeMs,
            message: $matched !== null
                ? null
                : (new ModelUnavailableException($model, $installed))->userMessage(),
            hint: $matched !== null
                ? null
                : (new ModelUnavailableException($model, $installed))->hint(),
        );
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function model(): string
    {
        return $this->settings->model();
    }

    public function endpoint(): string
    {
        return $this->settings->endpoint();
    }

    /* ---------------------------------------------------------------------- */
    /* Internals                                                              */
    /* ---------------------------------------------------------------------- */

    /**
     * The one path every completion takes: validate, send, parse, log.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $schema
     *
     * @throws AiException
     */
    private function run(
        string|PromptTemplate $prompt,
        AiRequestType $type,
        array $options,
        bool $structured,
        array $schema,
    ): AiResult {
        $template = $prompt instanceof PromptTemplate ? $prompt : null;
        $text = $prompt instanceof PromptTemplate ? $prompt->render() : trim($prompt);

        $model = (string) ($options['model'] ?? $this->model());
        $temperature = (float) ($options['temperature'] ?? $this->settings->temperature());
        $timeout = (int) ($options['timeout'] ?? $this->settings->timeout());
        $maxTokens = array_key_exists('max_tokens', $options)
            ? ($options['max_tokens'] === null ? null : (int) $options['max_tokens'])
            : $this->settings->maxTokens();

        $system = $options['system'] ?? $template?->system ?? $this->prompts->systemPrompt();

        // Optional: which lead this request is about, for the local log. Only
        // the recommendation flow sets it; Playground runs leave it null.
        $leadId = isset($options['lead_id']) ? (int) $options['lead_id'] : null;
        $companyId = isset($options['company_id']) ? (int) $options['company_id'] : null;

        if ($structured) {
            // Belt and braces: `format` constrains generation, and the
            // instruction helps models that honour it only loosely.
            $system = trim($system."\n\n".PromptLibrary::JSON_INSTRUCTION);
        }

        $startedAt = hrtime(true);

        try {
            $this->guard->assertLocal($this->endpoint());
            $this->assertPromptWithinLimit($text);

            // Structured requests must suppress reasoning; text requests leave it
            // to the model unless the caller says otherwise. See buildRequestBody().
            $think = array_key_exists('think', $options)
                ? ($options['think'] === null ? null : (bool) $options['think'])
                : ($structured ? false : null);

            $body = $this->buildRequestBody(
                $model, $text, $system, $temperature, $maxTokens, $structured, $schema, $think,
            );
            $payload = $this->postGenerate($body, $timeout, $model);

            $raw = $payload['response'] ?? null;

            if (! is_string($raw) || trim($raw) === '') {
                throw new InvalidAiResponseException(
                    'Ollama returned an empty completion for model ['.$model.']',
                    structured: $structured,
                );
            }

            [$responseText, $truncated] = $this->capResponse($raw);

            $data = $structured ? $this->parser->parse($responseText, $schema) : null;

            $executionMs = $this->elapsedMs($startedAt);

            $logId = $this->logger->logSuccess(
                provider: self::PROVIDER,
                model: $model,
                type: $type,
                prompt: $text,
                response: $responseText,
                executionMs: $executionMs,
                structured: $structured,
                template: $template?->name,
                promptTokens: $this->intOrNull($payload['prompt_eval_count'] ?? null),
                responseTokens: $this->intOrNull($payload['eval_count'] ?? null),
                temperature: $temperature,
                leadId: $leadId,
                companyId: $companyId,
            );

            return new AiResult(
                text: $responseText,
                data: $data,
                provider: self::PROVIDER,
                model: $payload['model'] ?? $model,
                requestType: $type,
                executionMs: $executionMs,
                structured: $structured,
                promptTokens: $this->intOrNull($payload['prompt_eval_count'] ?? null),
                responseTokens: $this->intOrNull($payload['eval_count'] ?? null),
                truncated: $truncated,
                logId: $logId,
            );
        } catch (AiException $e) {
            $this->logger->logFailure(
                provider: self::PROVIDER,
                model: $model,
                type: $type,
                prompt: $text,
                exception: $e,
                executionMs: $this->elapsedMs($startedAt),
                structured: $structured,
                template: $template?->name,
                temperature: $temperature,
                leadId: $leadId,
                companyId: $companyId,
            );

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function buildRequestBody(
        string $model,
        string $prompt,
        ?string $system,
        float $temperature,
        ?int $maxTokens,
        bool $structured,
        array $schema,
        ?bool $think,
    ): array {
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            // Non-streaming. See the class docblock on adding streaming later.
            'stream' => false,
            'options' => array_filter(
                [
                    'temperature' => $temperature,
                    'num_predict' => $maxTokens,
                ],
                fn ($value) => $value !== null,
            ),
        ];

        if (is_string($system) && trim($system) !== '') {
            $body['system'] = $system;
        }

        if ($structured) {
            // Ollama accepts a JSON Schema object here, or the string "json" for
            // unconstrained-but-valid JSON. Prefer the schema when given.
            $body['format'] = $schema !== [] ? $schema : 'json';
        }

        // Reasoning ("thinking") models need opposite handling per mode, and
        // getting this wrong is not subtle - it returns an empty completion.
        //
        // Verified against qwen3:4b on Ollama 0.32:
        //   structured + thinking on  -> the JSON is emitted into the separate
        //                                `thinking` field and `response` is EMPTY
        //   structured + think:false  -> clean JSON, and far faster
        //   text       + thinking on  -> clean `response`, reasoning kept aside
        //   text       + think:false  -> reasoning leaks into `response`
        //
        // So: suppress thinking for structured requests, leave it alone for text.
        // Non-reasoning models ignore the flag, so sending it is harmless.
        if ($think !== null) {
            $body['think'] = $think;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    private function postGenerate(array $body, int $timeout, string $model): array
    {
        // PHP's own max_execution_time would otherwise kill a request this
        // service is legitimately still waiting on. php.ini commonly caps at
        // 30s, while a local model on CPU can easily take minutes - so without
        // this, the configured AI timeout is a fiction and the user gets a
        // fatal error instead of a friendly one.
        //
        // A little margin on top so the HTTP timeout fires first and produces a
        // proper AiTimeoutException rather than PHP pulling the rug out.
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }

        try {
            $response = $this->client($timeout)->post('/api/generate', $body);
        } catch (ConnectionException $e) {
            // Laravel raises ConnectionException for both a refused connection
            // and a timeout; the message is what separates them.
            throw $this->isTimeout($e)
                ? new AiTimeoutException($timeout, $model)
                : new AiUnavailableException($this->endpoint(), $e->getMessage());
        }

        if ($response->status() === 404) {
            // Ollama answers 404 when the model is not pulled.
            throw new ModelUnavailableException($model, $this->getModels());
        }

        if ($response->failed()) {
            throw new InvalidAiResponseException(
                'Ollama returned HTTP '.$response->status().': '.$this->excerpt($response->body()),
            );
        }

        $payload = $this->decode($response);

        if ($payload === null) {
            throw new InvalidAiResponseException(
                'Ollama returned a non-JSON body: '.$this->excerpt($response->body()),
            );
        }

        return $payload;
    }

    /**
     * GET /api/tags - the local model list. Null means unreachable.
     *
     * @return array<string, mixed>|null
     */
    private function tags(): ?array
    {
        $this->guard->assertLocal($this->endpoint());

        try {
            $response = $this->client($this->settings->probeTimeout())->get('/api/tags');
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        return $this->decode($response);
    }

    /** Ollama's own version string, best effort - never fails the caller. */
    private function version(): ?string
    {
        try {
            $response = $this->client($this->settings->probeTimeout())->get('/api/version');
        } catch (Throwable) {
            return null;
        }

        $version = $response->successful() ? ($this->decode($response)['version'] ?? null) : null;

        return is_string($version) ? $version : null;
    }

    private function client(int $timeout): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->baseUrl($this->endpoint())
            ->timeout($timeout)
            // Fail fast when nothing is listening, rather than burning the full
            // generation timeout on a dead port.
            ->connectTimeout(min($timeout, max(2, $this->settings->probeTimeout())))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Ollama returns "qwen3:8b"; a user may reasonably configure "qwen3".
     * Accept the bare name as matching its :latest (or sole) tag.
     *
     * @param  list<string>  $installed
     */
    private function matchInstalled(string $target, array $installed): ?string
    {
        if (in_array($target, $installed, true)) {
            return $target;
        }

        if (! str_contains($target, ':')) {
            foreach ($installed as $name) {
                if (str_starts_with($name, $target.':')) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** @throws PromptTooLargeException */
    private function assertPromptWithinLimit(string $prompt): void
    {
        $limit = $this->settings->maxPromptChars();
        $length = mb_strlen($prompt);

        if ($length > $limit) {
            throw new PromptTooLargeException($length, $limit);
        }
    }

    /** @return array{0: string, 1: bool} The text, and whether it was truncated. */
    private function capResponse(string $raw): array
    {
        $limit = $this->settings->maxResponseChars();

        if (mb_strlen($raw) <= $limit) {
            return [$raw, false];
        }

        return [mb_substr($raw, 0, $limit), true];
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['timed out', 'timeout', 'operation too slow'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function elapsedMs(float|int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function excerpt(string $body, int $length = 300): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return mb_strlen($clean) <= $length ? $clean : mb_substr($clean, 0, $length).'…';
    }
}
