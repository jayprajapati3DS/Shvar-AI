<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Models\AiRequest;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\Exceptions\AiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The local AI request log.
 *
 * The important guarantee: failures are logged too. A log that only records
 * successes is useless for diagnosing why the AI stopped working.
 */
class AiLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSuccess(string $text = 'Hello.'): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => $text,
                'done' => true,
                'prompt_eval_count' => 12,
                'eval_count' => 8,
            ]),
        ]);
    }

    private function ai(): AIServiceInterface
    {
        return app(AIServiceInterface::class);
    }

    public function test_a_successful_request_is_logged(): void
    {
        $this->fakeSuccess('Local answer.');

        $result = $this->ai()->generate('Say something.', AiRequestType::General, ['temperature' => 0.3]);

        $log = AiRequest::sole();

        $this->assertSame('ollama', $log->provider);
        $this->assertSame('qwen3:8b', $log->model);
        $this->assertSame(AiRequestType::General, $log->request_type);
        $this->assertSame(AiRequestStatus::Success, $log->status);
        $this->assertSame('Say something.', $log->prompt);
        $this->assertSame('Local answer.', $log->response);
        $this->assertNull($log->error_message);
        $this->assertSame(12, $log->prompt_tokens);
        $this->assertSame(8, $log->response_tokens);
        $this->assertSame(0.3, $log->temperature);
        $this->assertNotNull($log->execution_time_ms);

        // The result carries the log id so the UI can link straight to it.
        $this->assertSame($log->id, $result->logId);
    }

    public function test_execution_time_is_recorded(): void
    {
        $this->fakeSuccess();

        $this->ai()->generate('Hello');

        $log = AiRequest::sole();

        $this->assertIsInt($log->execution_time_ms);
        $this->assertGreaterThanOrEqual(0, $log->execution_time_ms);
    }

    public function test_a_failed_request_is_logged_with_the_technical_reason(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        try {
            $this->ai()->generate('Will fail');
        } catch (AiException) {
            // expected
        }

        $log = AiRequest::sole();

        $this->assertSame(AiRequestStatus::Unavailable, $log->status);
        $this->assertSame('Will fail', $log->prompt);
        $this->assertNull($log->response);
        // Technical detail is kept here for diagnosis.
        $this->assertStringContainsString('cURL error 7', (string) $log->error_message);
    }

    public function test_each_failure_mode_records_its_own_status(): void
    {
        // Model missing -> ModelMissing, not a generic failure.
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'llama3:latest']]]),
            '*/api/generate' => Http::response(['error' => 'not found'], 404),
        ]);

        try {
            $this->ai()->generate('a');
        } catch (AiException) {
        }

        $this->assertSame(AiRequestStatus::ModelMissing, AiRequest::latest('id')->first()->status);

        // Timeout -> Timeout.
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));

        try {
            $this->ai()->generate('b');
        } catch (AiException) {
        }

        $this->assertSame(AiRequestStatus::Timeout, AiRequest::latest('id')->first()->status);

        // Oversized prompt -> Rejected, logged even though nothing was sent.
        config(['ai.limits.max_prompt_chars' => 5]);

        try {
            $this->ai()->generate('far too long for the limit');
        } catch (AiException) {
        }

        $this->assertSame(AiRequestStatus::Rejected, AiRequest::latest('id')->first()->status);
    }

    public function test_a_structured_request_is_flagged_in_the_log(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response(['model' => 'qwen3:8b', 'response' => '{"ok":true}']),
        ]);

        $this->ai()->generateStructured('Give me JSON');

        $this->assertTrue(AiRequest::sole()->structured);
    }

    public function test_stored_text_is_capped_so_the_local_database_cannot_grow_unbounded(): void
    {
        config(['ai.logging.max_stored_chars' => 100]);

        $this->fakeSuccess(str_repeat('z', 5000));

        $this->ai()->generate('Hello');

        $stored = (string) AiRequest::sole()->response;

        // Truncated, and marked as such so it is not mistaken for the full answer.
        $this->assertLessThan(5000, mb_strlen($stored));
        $this->assertStringContainsString('truncated for local storage', $stored);
    }

    public function test_logging_can_be_disabled_without_breaking_generation(): void
    {
        config(['ai.logging.enabled' => false]);

        $this->fakeSuccess('Still works.');

        $result = $this->ai()->generate('Hello');

        $this->assertSame('Still works.', $result->text);
        $this->assertNull($result->logId);
        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_the_log_survives_a_write_failure_without_losing_the_response(): void
    {
        $this->fakeSuccess('Answer regardless.');

        // Drop the table so the insert fails, simulating a broken local DB.
        \Illuminate\Support\Facades\Schema::drop('ai_requests');

        $result = $this->ai()->generate('Hello');

        // Generation still succeeds - logging is best-effort, not load-bearing.
        $this->assertSame('Answer regardless.', $result->text);
        $this->assertNull($result->logId);
    }
}
