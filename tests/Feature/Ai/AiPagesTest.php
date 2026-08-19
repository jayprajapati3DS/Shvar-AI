<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Models\AiRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The three Settings screens, end to end through HTTP.
 *
 * Ollama is faked throughout. The recurring assertion is that a broken backend
 * still renders a usable page with a plain-language explanation - never a 500,
 * never a stack trace.
 */
class AiPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A healthy Ollama.
     *
     * /api/generate answers according to what was asked: a structured request
     * (one carrying `format`) gets JSON back, a plain one gets text. Real Ollama
     * behaves this way, and ping() is a structured call while the Playground
     * text cases are not - a single canned body cannot serve both.
     */
    private function fakeReady(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/version' => Http::response(['version' => '0.5.7']),
            '*/api/generate' => function ($request) {
                $structured = isset($request->data()['format']);

                return Http::response([
                    'model' => 'qwen3:8b',
                    'response' => $structured ? '{"ok":true}' : 'OK',
                    'done' => true,
                    'prompt_eval_count' => 5,
                    'eval_count' => 2,
                ]);
            },
        ]);
    }

    private function fakeDown(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
    }

    /* ---------------------------------------------------------------- */
    /* Navigation                                                      */
    /* ---------------------------------------------------------------- */

    public function test_settings_redirects_to_the_ai_page(): void
    {
        $this->get('/settings')->assertRedirect('/settings/ai');
    }

    /* ---------------------------------------------------------------- */
    /* AI Configuration                                                */
    /* ---------------------------------------------------------------- */

    public function test_the_configuration_page_renders_when_ollama_is_ready(): void
    {
        config(['ai.ollama.model' => 'qwen3:8b']);
        $this->fakeReady();

        $this->get(route('settings.ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Ai')
                ->where('status.connected', true)
                ->where('status.model_installed', true)
                ->where('status.ready', true)
                ->where('settings.provider', 'ollama')
                ->where('settings.endpoint_is_read_only', true)
                ->has('status.installed_models', 1)
                ->has('requestTypes', 5)
                ->has('stats')
                ->has('defaults.system_prompt'));
    }

    public function test_the_configuration_page_renders_when_ollama_is_down(): void
    {
        $this->fakeDown();

        $this->get(route('settings.ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Ai')
                ->where('status.connected', false)
                ->where('status.ready', false)
                ->whereNot('status.message', null));
    }

    public function test_only_the_built_request_types_are_marked_implemented(): void
    {
        $this->fakeReady();

        $this->get(route('settings.ai.index'))
            ->assertInertia(function (Assert $page) {
                $types = collect($page->toArray()['props']['requestTypes']);

                // Phase 2 shipped General; Phase 3 shipped ProductRecommendation.
                foreach (['general', 'product_recommendation'] as $built) {
                    $this->assertTrue(
                        $types->firstWhere('value', $built)['implemented'],
                        "{$built} should be marked implemented.",
                    );
                }

                // Still unbuilt - email generation and follow-ups are Phase 4.
                foreach (['company_analysis', 'email_generation', 'follow_up_generation'] as $later) {
                    $this->assertFalse(
                        $types->firstWhere('value', $later)['implemented'],
                        "{$later} must not be marked implemented yet.",
                    );
                }
            });
    }

    /* ---------------------------------------------------------------- */
    /* Connection test                                                 */
    /* ---------------------------------------------------------------- */

    public function test_the_connection_test_reports_success_with_a_response_time(): void
    {
        $this->fakeReady();

        $this->post(route('settings.ai.test'))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Connection successful')
                && str_contains($message, 'responded in'));

        // A real round trip happened, and it was logged.
        $this->assertDatabaseCount('ai_requests', 1);
        $this->assertSame(AiRequestStatus::Success, AiRequest::sole()->status);
    }

    public function test_the_connection_test_reports_a_friendly_error_when_ollama_is_down(): void
    {
        $this->fakeDown();

        $response = $this->post(route('settings.ai.test'))->assertRedirect();

        $error = session('error');

        $this->assertStringContainsString('Unable to connect to Ollama', $error);
        $this->assertStringContainsString('Start Ollama', $error);
        // No leaked internals.
        $this->assertStringNotContainsString('Exception', $error);
        $this->assertStringNotContainsString('vendor', $error);
        $this->assertStringNotContainsString(base_path(), $error);

        $response->assertSessionMissing('success');
    }

    public function test_the_connection_test_reports_a_missing_model(): void
    {
        config(['ai.ollama.model' => 'qwen3:8b']);

        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'llama3:latest']]]),
            '*/api/generate' => Http::response(['error' => 'model not found'], 404),
        ]);

        $this->post(route('settings.ai.test'))->assertRedirect();

        $this->assertStringContainsString('is not installed', session('error'));
        $this->assertStringContainsString('ollama pull qwen3:8b', session('error'));
    }

    public function test_the_refresh_action_reports_the_probe_outcome(): void
    {
        $this->fakeReady();
        $this->post(route('settings.ai.refresh'))->assertSessionHas('success');

        $this->fakeDown();
        $this->post(route('settings.ai.refresh'))->assertSessionHas('error');
    }

    /* ---------------------------------------------------------------- */
    /* Playground                                                      */
    /* ---------------------------------------------------------------- */

    public function test_the_playground_renders_with_no_result(): void
    {
        $this->fakeReady();

        $this->get(route('settings.ai.playground'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/AiPlayground')
                ->where('result', null)
                ->where('status.ready', true)
                ->has('examplePrompts')
                ->has('systemPrompt'));
    }

    public function test_running_a_prompt_returns_the_result_and_logs_it(): void
    {
        $this->fakeReady();

        $this->post(route('settings.ai.playground.run'), ['prompt' => 'Say OK'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/AiPlayground')
                ->where('result.text', 'OK')
                ->where('result.prompt', 'Say OK')
                ->where('result.model', 'qwen3:8b')
                ->where('result.request_type', 'general')
                ->where('result.structured', false)
                ->whereNot('result.log_id', null));

        $log = AiRequest::sole();
        $this->assertSame(AiRequestType::General, $log->request_type);
        $this->assertSame('general', $log->template);
    }

    public function test_a_structured_run_returns_parsed_data(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/version' => Http::response(['version' => '0.5.7']),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => '{"status":"ready","ok":true}',
            ]),
        ]);

        $this->post(route('settings.ai.playground.run'), [
            'prompt' => 'Give me JSON',
            'structured' => true,
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('result.structured', true)
                ->where('result.data.status', 'ready')
                ->where('result.data.ok', true));
    }

    public function test_an_empty_prompt_is_rejected(): void
    {
        $this->fakeReady();

        $this->post(route('settings.ai.playground.run'), ['prompt' => ''])
            ->assertSessionHasErrors('prompt');
    }

    public function test_an_oversized_prompt_is_rejected_with_a_field_error(): void
    {
        config(['ai.limits.max_prompt_chars' => 50]);
        $this->fakeReady();

        $this->post(route('settings.ai.playground.run'), ['prompt' => str_repeat('x', 51)])
            ->assertSessionHasErrors('prompt');

        // Rejected by validation, so nothing reached the model.
        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_a_model_that_is_not_installed_is_rejected_with_a_field_error(): void
    {
        $this->fakeReady();

        $this->post(route('settings.ai.playground.run'), [
            'prompt' => 'Hello',
            'model' => 'not-installed:70b',
        ])->assertSessionHasErrors('model');

        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_a_failed_run_shows_a_friendly_error_and_keeps_the_input(): void
    {
        $this->fakeDown();

        $this->post(route('settings.ai.playground.run'), ['prompt' => 'Say OK'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Unable to connect to Ollama'));

        // The prompt is preserved so the user does not retype it.
        $this->assertSame('Say OK', old('prompt'));
    }

    public function test_the_playground_only_ever_uses_the_general_request_type(): void
    {
        $this->fakeReady();

        $this->post(route('settings.ai.playground.run'), ['prompt' => 'Anything']);

        // Guards against Phase 3 types leaking in early. pluck() returns cast
        // enum instances, so compare on ->value.
        $this->assertSame(
            [AiRequestType::General->value],
            AiRequest::pluck('request_type')
                ->map(fn (AiRequestType $type) => $type->value)
                ->unique()
                ->values()
                ->all(),
        );
    }

    /* ---------------------------------------------------------------- */
    /* Logs                                                            */
    /* ---------------------------------------------------------------- */

    public function test_the_logs_page_renders_when_empty(): void
    {
        $this->fakeReady();

        $this->get(route('settings.ai.logs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/AiLogs')
                ->has('requests.data', 0));
    }

    public function test_the_logs_page_lists_entries_without_the_bulky_text(): void
    {
        AiRequest::create([
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
            'request_type' => AiRequestType::General,
            'status' => AiRequestStatus::Success,
            'prompt' => 'A very long prompt',
            'response' => 'A very long response',
            'execution_time_ms' => 1820,
        ]);

        $this->get(route('settings.ai.logs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('requests.data', 1)
                ->where('requests.data.0.model', 'qwen3:8b')
                ->where('requests.data.0.seconds', 1.82)
                ->where('requests.data.0.status_label', 'Success')
                // Prompt/response are fetched per row, not shipped in the list.
                ->missing('requests.data.0.prompt')
                ->missing('requests.data.0.response'));
    }

    public function test_the_log_detail_endpoint_returns_the_prompt_and_response(): void
    {
        $log = AiRequest::create([
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
            'request_type' => AiRequestType::General,
            'status' => AiRequestStatus::Success,
            'prompt' => 'The full prompt',
            'response' => 'The full response',
            'execution_time_ms' => 500,
        ]);

        $this->getJson(route('settings.ai.logs.show', $log))
            ->assertOk()
            ->assertJsonPath('request.prompt', 'The full prompt')
            ->assertJsonPath('request.response', 'The full response');
    }

    public function test_the_logs_page_can_be_filtered(): void
    {
        $base = [
            'provider' => 'ollama',
            'request_type' => AiRequestType::General,
            'execution_time_ms' => 100,
        ];

        AiRequest::create([...$base, 'model' => 'qwen3:8b', 'status' => AiRequestStatus::Success, 'prompt' => 'alpha']);
        AiRequest::create([...$base, 'model' => 'llama3:latest', 'status' => AiRequestStatus::Timeout, 'prompt' => 'beta']);

        $this->get(route('settings.ai.logs', ['status' => 'timeout']))
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)
                ->where('requests.data.0.model', 'llama3:latest'));

        $this->get(route('settings.ai.logs', ['model' => 'qwen3:8b']))
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));

        $this->get(route('settings.ai.logs', ['search' => 'beta']))
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));
    }

    public function test_a_log_entry_can_be_deleted_and_the_log_cleared(): void
    {
        $log = AiRequest::create([
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
            'request_type' => AiRequestType::General,
            'status' => AiRequestStatus::Success,
        ]);

        $this->delete(route('settings.ai.logs.destroy', $log))->assertRedirect();
        $this->assertDatabaseCount('ai_requests', 0);

        AiRequest::create([
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
            'request_type' => AiRequestType::General,
            'status' => AiRequestStatus::Success,
        ]);

        $this->delete(route('settings.ai.logs.clear'))->assertRedirect();
        $this->assertDatabaseCount('ai_requests', 0);
    }
}
