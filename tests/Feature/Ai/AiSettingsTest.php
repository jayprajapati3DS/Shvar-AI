<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiSetting;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\AiSettings;
use App\Services\AI\Exceptions\InsecureEndpointException;
use App\Services\AI\LocalEndpointGuard;
use App\Services\AI\PromptLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings persistence, and the endpoint security boundary.
 *
 * The boundary tests are the important ones: they assert that no request from
 * the browser can point AI traffic at a remote host.
 */
class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeConnected(array $models = ['qwen3:8b', 'llama3:latest']): void
    {
        Http::fake([
            '*/api/tags' => Http::response([
                'models' => array_map(fn ($m) => ['name' => $m], $models),
            ]),
            '*/api/version' => Http::response(['version' => '0.5.7']),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => 'OK',
                'done' => true,
            ]),
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Persistence */
    /* ---------------------------------------------------------------- */

    public function test_settings_fall_back_to_config_when_nothing_is_saved(): void
    {
        config([
            'ai.ollama.model' => 'config-model',
            'ai.defaults.temperature' => 0.4,
            'ai.defaults.timeout' => 90,
        ]);

        $settings = app(AiSettings::class);

        $this->assertSame('config-model', $settings->model());
        $this->assertSame(0.4, $settings->temperature());
        $this->assertSame(90, $settings->timeout());
    }

    public function test_saved_settings_override_config(): void
    {
        config(['ai.ollama.model' => 'config-model']);

        app(AiSettings::class)->save([
            'model' => 'saved-model',
            'temperature' => 0.9,
            'timeout' => 45,
            'max_tokens' => 512,
        ]);

        // A fresh instance, not the cached singleton: proves the values really
        // round-tripped through the database rather than sitting in memory.
        $settings = new AiSettings;

        $this->assertSame('saved-model', $settings->model());
        $this->assertSame(0.9, $settings->temperature());
        $this->assertSame(45, $settings->timeout());
        $this->assertSame(512, $settings->maxTokens());
    }

    public function test_settings_persist_across_requests(): void
    {
        $this->fakeConnected();

        $this->put(route('settings.ai.update'), [
            'model' => 'llama3:latest',
            'temperature' => 0.2,
            'timeout' => 60,
        ])->assertRedirect();

        $this->assertDatabaseHas('ai_settings', ['key' => 'model', 'value' => 'llama3:latest']);

        // And the next request sees it.
        $this->get(route('settings.ai.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.model', 'llama3:latest')
                ->where('settings.temperature', 0.2)
                ->where('settings.timeout', 60));
    }

    public function test_out_of_range_values_are_rejected_by_validation(): void
    {
        $this->fakeConnected();

        $this->put(route('settings.ai.update'), [
            'model' => 'qwen3:8b',
            'temperature' => 99,
            'timeout' => 60,
        ])->assertSessionHasErrors('temperature');

        $this->put(route('settings.ai.update'), [
            'model' => 'qwen3:8b',
            'temperature' => 0.5,
            'timeout' => 1,
        ])->assertSessionHasErrors('timeout');
    }

    public function test_values_are_clamped_when_written_directly(): void
    {
        $settings = app(AiSettings::class);

        // A non-HTTP caller bypasses the form request, so the setter clamps too.
        $settings->save(['temperature' => 50, 'timeout' => 99999]);

        $fresh = new AiSettings;

        $this->assertSame((float) config('ai.limits.max_temperature'), $fresh->temperature());
        $this->assertSame((int) config('ai.limits.max_timeout'), $fresh->timeout());
    }

    /* ---------------------------------------------------------------- */
    /* System prompt */
    /* ---------------------------------------------------------------- */

    public function test_the_default_system_prompt_is_the_library_base(): void
    {
        $settings = app(AiSettings::class);

        $this->assertSame(PromptLibrary::BASE_SYSTEM_PROMPT, $settings->systemPrompt());
        $this->assertTrue($settings->isUsingDefaultSystemPrompt());
        // The clauses that stop a sales assistant inventing clearances.
        $this->assertStringContainsString('never invent facts', $settings->systemPrompt());
        $this->assertStringContainsString('regulatory', $settings->systemPrompt());
    }

    public function test_the_system_prompt_can_be_overridden_and_reset(): void
    {
        $this->fakeConnected();

        $this->put(route('settings.ai.update'), [
            'model' => 'qwen3:8b',
            'temperature' => 0.7,
            'timeout' => 60,
            'system_prompt' => 'You are a custom assistant.',
        ])->assertRedirect();

        $this->assertSame('You are a custom assistant.', (new AiSettings)->systemPrompt());
        $this->assertFalse((new AiSettings)->isUsingDefaultSystemPrompt());

        $this->delete(route('settings.ai.system-prompt.reset'))->assertRedirect();

        $this->assertSame(PromptLibrary::BASE_SYSTEM_PROMPT, (new AiSettings)->systemPrompt());
    }

    /* ---------------------------------------------------------------- */
    /* Endpoint security boundary */
    /* ---------------------------------------------------------------- */

    public function test_the_endpoint_is_read_from_config_not_the_database(): void
    {
        config(['ai.ollama.url' => 'http://localhost:11434']);

        // Plant a row that would repoint the endpoint if it were ever honoured.
        AiSetting::create(['key' => 'url', 'value' => 'https://evil.example.com']);
        AiSetting::create(['key' => 'endpoint', 'value' => 'https://evil.example.com']);

        $this->assertSame('http://localhost:11434', (new AiSettings)->endpoint());
    }

    public function test_the_settings_form_cannot_change_the_endpoint(): void
    {
        $this->fakeConnected();
        config(['ai.ollama.url' => 'http://localhost:11434']);

        $this->put(route('settings.ai.update'), [
            'model' => 'qwen3:8b',
            'temperature' => 0.7,
            'timeout' => 60,
            // Every spelling an attacker might try.
            'url' => 'https://evil.example.com',
            'endpoint' => 'https://evil.example.com',
            'ollama_url' => 'https://evil.example.com',
            'provider' => 'openai',
        ])->assertRedirect();

        $this->assertSame('http://localhost:11434', (new AiSettings)->endpoint());
        $this->assertSame('ollama', (new AiSettings)->provider());

        // Nothing was written for those keys at all.
        $this->assertDatabaseMissing('ai_settings', ['key' => 'url']);
        $this->assertDatabaseMissing('ai_settings', ['key' => 'endpoint']);
        $this->assertDatabaseMissing('ai_settings', ['key' => 'provider']);
    }

    public function test_writable_keys_do_not_include_anything_endpoint_related(): void
    {
        foreach (['url', 'endpoint', 'ollama_url', 'provider', 'host', 'base_url'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                AiSettings::WRITABLE_KEYS,
                "[{$forbidden}] must not be user-writable.",
            );
        }
    }

    public function test_the_guard_accepts_local_addresses(): void
    {
        $guard = app(LocalEndpointGuard::class);

        foreach ([
            'http://localhost:11434',
            'http://127.0.0.1:11434',
            'http://[::1]:11434',
            'https://localhost',
            'localhost:11434',
        ] as $url) {
            $this->assertTrue($guard->isLocal($url), "{$url} should be considered local.");
        }
    }

    public function test_the_guard_rejects_remote_addresses(): void
    {
        $guard = app(LocalEndpointGuard::class);

        foreach ([
            'https://api.example.com',
            'http://192.168.1.50:11434',
            'http://ollama.mycompany.io',
            'https://localhost.evil.example.com',
        ] as $url) {
            $this->assertFalse($guard->isLocal($url), "{$url} must NOT be considered local.");
        }
    }

    public function test_a_remote_endpoint_blocks_generation_entirely(): void
    {
        config(['ai.ollama.url' => 'https://api.example.com']);
        Http::fake();

        try {
            app(AIServiceInterface::class)->generate('Leak my CRM data');
            $this->fail('Expected InsecureEndpointException.');
        } catch (InsecureEndpointException $e) {
            $this->assertStringContainsString('not on this machine', $e->userMessage());
        }

        // The critical assertion: not a single byte was sent.
        Http::assertNothingSent();
    }

    public function test_a_remote_endpoint_makes_status_report_not_connected(): void
    {
        config(['ai.ollama.url' => 'https://api.example.com']);
        Http::fake();

        $status = app(AIServiceInterface::class)->status();

        $this->assertFalse($status->connected);
        $this->assertFalse($status->isReady());
        Http::assertNothingSent();
    }
}
