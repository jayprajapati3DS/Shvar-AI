<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Models\AiRequest;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\Exceptions\AiTimeoutException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\Exceptions\InvalidAiResponseException;
use App\Services\AI\Exceptions\ModelUnavailableException;
use App\Services\AI\Exceptions\PromptTooLargeException;
use App\Services\AI\OllamaAIService;
use App\Services\AI\PromptTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Ollama service, driven entirely through faked HTTP.
 *
 * Ollama does NOT need to be running for these to pass - that is the point.
 * Every response shape, and every failure mode, is simulated.
 */
class OllamaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function ai(): AIServiceInterface
    {
        return app(AIServiceInterface::class);
    }

    /** A realistic /api/tags body. */
    private function tagsResponse(array $models = ['qwen3:8b', 'llama3:latest']): array
    {
        return [
            'models' => array_map(fn (string $name) => [
                'name' => $name,
                'model' => $name,
                'size' => 4_900_000_000,
                'digest' => str_repeat('a', 64),
            ], $models),
        ];
    }

    /** A realistic non-streaming /api/generate body. */
    private function generateResponse(string $text, string $model = 'qwen3:8b'): array
    {
        return [
            'model' => $model,
            'created_at' => '2026-02-01T10:00:00Z',
            'response' => $text,
            'done' => true,
            'total_duration' => 1_800_000_000,
            'prompt_eval_count' => 42,
            'eval_count' => 17,
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Wiring */
    /* ---------------------------------------------------------------- */

    public function test_the_container_binds_the_ollama_implementation(): void
    {
        $this->assertInstanceOf(OllamaAIService::class, $this->ai());
    }

    public function test_it_reports_the_configured_provider_endpoint_and_model(): void
    {
        config(['ai.ollama.url' => 'http://localhost:11434/', 'ai.ollama.model' => 'qwen3:8b']);

        $ai = $this->ai();

        $this->assertSame('ollama', $ai->provider());
        // Trailing slash normalised, so base URL joins cannot double up.
        $this->assertSame('http://localhost:11434', $ai->endpoint());
        $this->assertSame('qwen3:8b', $ai->model());
    }

    /* ---------------------------------------------------------------- */
    /* Availability + model discovery */
    /* ---------------------------------------------------------------- */

    public function test_is_available_is_true_when_ollama_answers(): void
    {
        Http::fake(['*/api/tags' => Http::response($this->tagsResponse())]);

        $this->assertTrue($this->ai()->isAvailable());
    }

    public function test_is_available_is_false_when_ollama_is_not_running(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        // Must return false, not throw - the UI depends on this.
        $this->assertFalse($this->ai()->isAvailable());
    }

    public function test_get_models_lists_installed_models_sorted_and_unique(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse(['llama3:latest', 'qwen3:8b', 'llama3:latest'])),
        ]);

        $this->assertSame(['llama3:latest', 'qwen3:8b'], $this->ai()->getModels());
    }

    public function test_get_models_returns_an_empty_list_when_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertSame([], $this->ai()->getModels());
    }

    public function test_get_models_handles_ollama_having_no_models_pulled(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []])]);

        $ai = $this->ai();

        $this->assertTrue($ai->isAvailable());
        $this->assertSame([], $ai->getModels());
        $this->assertFalse($ai->hasModel());
    }

    public function test_has_model_accepts_a_bare_name_matching_a_tagged_install(): void
    {
        config(['ai.ollama.model' => 'llama3']);
        Http::fake(['*/api/tags' => Http::response($this->tagsResponse(['llama3:latest']))]);

        // "llama3" should match the installed "llama3:latest".
        $this->assertTrue($this->ai()->hasModel());
    }

    public function test_has_model_is_false_for_a_model_that_is_not_installed(): void
    {
        Http::fake(['*/api/tags' => Http::response($this->tagsResponse(['llama3:latest']))]);

        $this->assertFalse($this->ai()->hasModel('mistral:7b'));
    }

    /* ---------------------------------------------------------------- */
    /* Status snapshot */
    /* ---------------------------------------------------------------- */

    public function test_status_reports_connected_and_installed_when_all_is_well(): void
    {
        config(['ai.ollama.model' => 'qwen3:8b']);

        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/version' => Http::response(['version' => '0.5.7']),
        ]);

        $status = $this->ai()->status();

        $this->assertTrue($status->connected);
        $this->assertTrue($status->modelInstalled);
        $this->assertTrue($status->isReady());
        $this->assertSame('0.5.7', $status->version);
        $this->assertNull($status->message);
    }

    public function test_status_reports_a_friendly_message_when_ollama_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $status = $this->ai()->status();

        $this->assertFalse($status->connected);
        $this->assertFalse($status->isReady());
        $this->assertStringContainsString('Unable to connect to Ollama', (string) $status->message);
        // No stack trace, no exception class name.
        $this->assertStringNotContainsString('Exception', (string) $status->message);
    }

    public function test_status_distinguishes_connected_but_model_missing(): void
    {
        config(['ai.ollama.model' => 'qwen3:8b']);

        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse(['llama3:latest'])),
            '*/api/version' => Http::response(['version' => '0.5.7']),
        ]);

        $status = $this->ai()->status();

        $this->assertTrue($status->connected);
        $this->assertFalse($status->modelInstalled);
        $this->assertFalse($status->isReady());
        $this->assertStringContainsString('is not installed', (string) $status->message);
        $this->assertStringContainsString('ollama pull qwen3:8b', (string) $status->hint);
    }

    /* ---------------------------------------------------------------- */
    /* Successful generation */
    /* ---------------------------------------------------------------- */

    public function test_generate_returns_the_completion(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('Hello from a local model.')),
        ]);

        $result = $this->ai()->generate('Say hello.');

        $this->assertSame('Hello from a local model.', $result->text);
        $this->assertSame('ollama', $result->provider);
        $this->assertSame('qwen3:8b', $result->model);
        $this->assertSame(AiRequestType::General, $result->requestType);
        $this->assertSame(42, $result->promptTokens);
        $this->assertSame(17, $result->responseTokens);
        $this->assertFalse($result->structured);
        $this->assertNull($result->data);
    }

    public function test_generate_sends_a_non_streaming_request_with_the_expected_options(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('ok')),
        ]);

        $this->ai()->generate('Ping', AiRequestType::General, [
            'model' => 'qwen3:8b',
            'temperature' => 0.25,
            'max_tokens' => 64,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            $body = $request->data();

            return $body['model'] === 'qwen3:8b'
                && $body['stream'] === false
                && $body['options']['temperature'] === 0.25
                && $body['options']['num_predict'] === 64
                && str_contains($body['system'], 'local AI assistant');
        });
    }

    public function test_a_prompt_template_is_rendered_and_its_system_prompt_used(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('ok')),
        ]);

        $template = new PromptTemplate(
            name: 'greeting',
            template: 'Greet {{ name }} from {{ city }}.',
            system: 'You are terse.',
        );

        $this->ai()->generate($template->with(['name' => 'Asha', 'city' => 'Chennai']));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            return $request->data()['prompt'] === 'Greet Asha from Chennai.'
                && $request->data()['system'] === 'You are terse.';
        });

        // The template name is recorded, so the log shows which prompt ran.
        $this->assertSame('greeting', AiRequest::sole()->template);
    }

    /* ---------------------------------------------------------------- */
    /* Failure modes */
    /* ---------------------------------------------------------------- */

    public function test_a_refused_connection_raises_ai_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(AiUnavailableException::class);

        $this->ai()->generate('Hello');
    }

    public function test_ai_unavailable_carries_a_user_safe_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to localhost port 11434'));

        try {
            $this->ai()->generate('Hello');
            $this->fail('Expected AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('Unable to connect to Ollama', $e->userMessage());
            $this->assertStringContainsString('Please verify that Ollama is running', $e->userMessage());
            // The cURL detail belongs in the log, not in front of the user.
            $this->assertStringNotContainsString('cURL', $e->userMessage());
            $this->assertStringContainsString('cURL', $e->getMessage());
        }
    }

    public function test_a_timeout_raises_ai_timeout_not_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120000 ms'));

        try {
            $this->ai()->generate('Hello', AiRequestType::General, ['timeout' => 30]);
            $this->fail('Expected AiTimeoutException.');
        } catch (AiTimeoutException $e) {
            $this->assertStringContainsString('did not respond within 30 seconds', $e->userMessage());
            $this->assertSame(AiRequestStatus::Timeout, $e->status());
        }
    }

    public function test_a_404_from_generate_raises_model_unavailable(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse(['llama3:latest'])),
            '*/api/generate' => Http::response(['error' => 'model "qwen3:8b" not found'], 404),
        ]);

        try {
            $this->ai()->generate('Hello');
            $this->fail('Expected ModelUnavailableException.');
        } catch (ModelUnavailableException $e) {
            $this->assertStringContainsString('is not installed', $e->userMessage());
            $this->assertStringContainsString('ollama pull', $e->hint());
            // Tells you what you do have.
            $this->assertStringContainsString('llama3:latest', $e->hint());
        }
    }

    public function test_a_server_error_raises_invalid_response(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response('internal failure', 500),
        ]);

        $this->expectException(InvalidAiResponseException::class);

        $this->ai()->generate('Hello');
    }

    public function test_a_non_json_body_raises_invalid_response(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response('<html>not json</html>', 200),
        ]);

        $this->expectException(InvalidAiResponseException::class);

        $this->ai()->generate('Hello');
    }

    public function test_an_empty_completion_raises_invalid_response(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('   ')),
        ]);

        $this->expectException(InvalidAiResponseException::class);

        $this->ai()->generate('Hello');
    }

    public function test_an_oversized_prompt_is_rejected_before_any_request(): void
    {
        config(['ai.limits.max_prompt_chars' => 100]);
        Http::fake();

        try {
            $this->ai()->generate(str_repeat('x', 101));
            $this->fail('Expected PromptTooLargeException.');
        } catch (PromptTooLargeException $e) {
            $this->assertStringContainsString('too long', $e->userMessage());
        }

        // Nothing was sent - the guard runs before the transport.
        Http::assertNothingSent();
    }

    /* ---------------------------------------------------------------- */
    /* Reasoning ("thinking") models */
    /* ---------------------------------------------------------------- */

    public function test_a_structured_request_suppresses_model_thinking(): void
    {
        // Regression guard. Verified against qwen3:4b on Ollama 0.32: with
        // thinking left on, a structured request has its JSON routed into a
        // separate `thinking` field and `response` comes back EMPTY - so every
        // structured call fails. `think: false` is required, not an optimisation.
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('{"ok":true}')),
        ]);

        $this->ai()->generateStructured('Give me JSON');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            return ($request->data()['think'] ?? null) === false;
        });
    }

    public function test_a_plain_text_request_leaves_thinking_to_the_model(): void
    {
        // The opposite default: with thinking on, a reasoning model keeps its
        // chain of thought out of `response`. Forcing it off makes the reasoning
        // leak into the answer instead.
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('An answer.')),
        ]);

        $this->ai()->generate('A question');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            return ! array_key_exists('think', $request->data());
        });
    }

    public function test_an_explicit_think_option_overrides_the_default(): void
    {
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('An answer.')),
        ]);

        $this->ai()->generate('A question', AiRequestType::General, ['think' => false]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            return ($request->data()['think'] ?? null) === false;
        });
    }

    public function test_ping_is_a_structured_round_trip(): void
    {
        // Structured on purpose: on a reasoning model it is faster, returns a
        // clean answer rather than the model musing aloud, and proves the
        // structured path works - which is what later features depend on.
        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse('{"ok":true}')),
        ]);

        $result = $this->ai()->ping();

        $this->assertTrue($result->structured);
        $this->assertSame(true, $result->get('ok'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            $body = $request->data();

            return isset($body['format']) && ($body['think'] ?? null) === false;
        });
    }

    public function test_an_oversized_response_is_truncated_rather_than_stored_whole(): void
    {
        config(['ai.limits.max_response_chars' => 50]);

        Http::fake([
            '*/api/tags' => Http::response($this->tagsResponse()),
            '*/api/generate' => Http::response($this->generateResponse(str_repeat('y', 500))),
        ]);

        $result = $this->ai()->generate('Hello');

        $this->assertTrue($result->truncated);
        $this->assertSame(50, mb_strlen($result->text));
    }
}
