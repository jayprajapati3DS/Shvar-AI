<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiRequestType;
use App\Services\AI\AIServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Text that is not valid UTF-8 must not break an AI call.
 *
 * Found the hard way, classifying a genuine 2.4 KB Outlook reply: MAPI hands
 * back Windows-1252 for anything typed with a smart quote, an en-dash or an
 * accented name, which is most real business email. json_encode inside the HTTP
 * client rejects those bytes, and the failure surfaces as
 *
 *     GuzzleHttp\Exception\InvalidArgumentException
 *     json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded
 *
 * from a place that says nothing about where the bad byte came from.
 *
 * Outlook is only one source. A CSV file saved from Excel and a paste from Word
 * are two more, and both already flow into prompts. So the guard sits at the
 * transport, where everything converges.
 */
class PromptEncodingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOllama(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => 'ok',
                'done' => true,
            ]),
        ]);
    }

    /** The bytes Outlook actually emits: curly quotes, en-dash, non-breaking space. */
    private function windows1252(): string
    {
        return "We\x92d like to discuss \x93patient-specific\x94 planning \x96 next week\xA0please.";
    }

    public function test_the_fixture_really_is_invalid_utf8(): void
    {
        // Otherwise the tests below would pass for the wrong reason.
        $this->assertFalse(mb_check_encoding($this->windows1252(), 'UTF-8'));
        $this->assertFalse(json_encode(['p' => $this->windows1252()]) !== false);
    }

    public function test_a_prompt_with_windows_1252_bytes_still_reaches_the_model(): void
    {
        $this->fakeOllama();

        $result = app(AIServiceInterface::class)->generate(
            $this->windows1252(),
            AiRequestType::General,
        );

        $this->assertSame('ok', $result->text);
    }

    public function test_the_request_body_that_goes_out_is_valid_utf8(): void
    {
        $this->fakeOllama();

        app(AIServiceInterface::class)->generate($this->windows1252(), AiRequestType::General);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            // Encodable, which is the whole point...
            return mb_check_encoding($prompt, 'UTF-8')
                && json_encode(['p' => $prompt]) !== false
                // ...and the characters survived rather than being stripped.
                && str_contains($prompt, 'patient-specific')
                && str_contains($prompt, 'planning');
        });
    }

    public function test_an_invalid_system_prompt_is_cleaned_too(): void
    {
        $this->fakeOllama();

        app(AIServiceInterface::class)->generate(
            'A clean prompt.',
            AiRequestType::General,
            ['system' => $this->windows1252()],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            return mb_check_encoding($request->data()['system'] ?? '', 'UTF-8');
        });
    }

    public function test_clean_utf8_is_left_exactly_as_it_was(): void
    {
        $this->fakeOllama();

        // Real UTF-8 curly quotes and an accented name must pass through
        // untouched - the guard must not mangle text that was already fine.
        $clean = 'Léa Bianchi asked about “patient-specific” planning — next week.';

        app(AIServiceInterface::class)->generate($clean, AiRequestType::General);

        Http::assertSent(function ($request) use ($clean) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            return ($request->data()['prompt'] ?? '') === $clean;
        });
    }
}
