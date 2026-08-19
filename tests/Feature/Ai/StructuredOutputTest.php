<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\AI\AIServiceInterface;
use App\Services\AI\Exceptions\InvalidAiResponseException;
use App\Services\AI\StructuredResponseParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Structured (JSON) output.
 *
 * The realistic failure here is not "the model refuses JSON" - it is that a
 * small local model wraps JSON in a code fence, or prefixes it with "Sure!".
 * These tests pin the recovery behaviour, and pin that an unrecoverable
 * response fails loudly rather than returning a guess.
 */
class StructuredOutputTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTagsAndResponse(string $modelOutput): void
    {
        Http::fake([
            '*/api/tags' => Http::response([
                'models' => [['name' => 'qwen3:8b', 'model' => 'qwen3:8b']],
            ]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => $modelOutput,
                'done' => true,
            ]),
        ]);
    }

    private function ai(): AIServiceInterface
    {
        return app(AIServiceInterface::class);
    }

    /* ---------------------------------------------------------------- */
    /* End-to-end through the service                                  */
    /* ---------------------------------------------------------------- */

    public function test_it_decodes_a_clean_json_object(): void
    {
        $this->fakeTagsAndResponse('{"recommendation":"MySegmenter","confidence":0.87,"reason":"They need segmentation."}');

        $result = $this->ai()->generateStructured('Recommend a product.');

        $this->assertTrue($result->structured);
        $this->assertSame('MySegmenter', $result->get('recommendation'));
        $this->assertSame(0.87, $result->get('confidence'));
        $this->assertSame('They need segmentation.', $result->get('reason'));
    }

    public function test_it_asks_ollama_to_constrain_output_to_json(): void
    {
        $this->fakeTagsAndResponse('{"ok":true}');

        $this->ai()->generateStructured('Anything');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            $body = $request->data();

            // No schema given -> Ollama's generic JSON mode, plus a system-prompt
            // instruction for models that honour `format` only loosely.
            return ($body['format'] ?? null) === 'json'
                && str_contains($body['system'], 'single valid JSON object');
        });
    }

    public function test_a_json_schema_is_forwarded_to_ollama_when_given(): void
    {
        $this->fakeTagsAndResponse('{"confidence":0.5}');

        $schema = [
            'type' => 'object',
            'properties' => ['confidence' => ['type' => 'number']],
            'required' => ['confidence'],
        ];

        $this->ai()->generateStructured('Anything', $schema);

        Http::assertSent(function ($request) use ($schema) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            return $request->data()['format'] === $schema;
        });
    }

    public function test_a_missing_required_key_is_a_failure_not_a_partial_result(): void
    {
        $this->fakeTagsAndResponse('{"reason":"no confidence value here"}');

        $this->expectException(InvalidAiResponseException::class);

        $this->ai()->generateStructured('Anything', [
            'type' => 'object',
            'required' => ['recommendation', 'confidence'],
        ]);
    }

    public function test_unparseable_json_raises_a_user_safe_error(): void
    {
        $this->fakeTagsAndResponse('I am afraid I cannot do that.');

        try {
            $this->ai()->generateStructured('Anything');
            $this->fail('Expected InvalidAiResponseException.');
        } catch (InvalidAiResponseException $e) {
            $this->assertSame(
                'The local model did not return valid structured data.',
                $e->userMessage(),
            );
            // The raw model output is kept for the log, not shown to the user.
            $this->assertStringContainsString('I am afraid', $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------- */
    /* Parser recovery, unit level                                     */
    /* ---------------------------------------------------------------- */

    public function test_the_parser_strips_a_json_code_fence(): void
    {
        $parser = app(StructuredResponseParser::class);

        $decoded = $parser->parse("```json\n{\"status\":\"ready\",\"ok\":true}\n```");

        $this->assertSame(['status' => 'ready', 'ok' => true], $decoded);
    }

    public function test_the_parser_strips_a_bare_code_fence(): void
    {
        $parser = app(StructuredResponseParser::class);

        $this->assertSame(['ok' => true], $parser->parse("```\n{\"ok\":true}\n```"));
    }

    public function test_the_parser_extracts_json_surrounded_by_commentary(): void
    {
        $parser = app(StructuredResponseParser::class);

        $decoded = $parser->parse(
            'Sure! Here is the JSON you asked for: {"status":"ready","ok":true} Let me know if you need more.'
        );

        $this->assertSame(['status' => 'ready', 'ok' => true], $decoded);
    }

    public function test_the_parser_is_not_confused_by_braces_inside_strings(): void
    {
        $parser = app(StructuredResponseParser::class);

        // A regex-based extractor would stop at the first } inside the string.
        $decoded = $parser->parse('{"reason":"Use the {placeholder} syntax","ok":true}');

        $this->assertSame('Use the {placeholder} syntax', $decoded['reason']);
        $this->assertTrue($decoded['ok']);
    }

    public function test_the_parser_handles_escaped_quotes(): void
    {
        $parser = app(StructuredResponseParser::class);

        $decoded = $parser->parse('{"quote":"They said \"yes\" on the call","ok":true}');

        $this->assertSame('They said "yes" on the call', $decoded['quote']);
    }

    public function test_the_parser_handles_nested_objects(): void
    {
        $parser = app(StructuredResponseParser::class);

        $decoded = $parser->parse('prefix {"outer":{"inner":{"deep":1}},"ok":true} suffix');

        $this->assertSame(1, $decoded['outer']['inner']['deep']);
    }

    public function test_the_parser_rejects_a_top_level_array(): void
    {
        $parser = app(StructuredResponseParser::class);

        // Valid JSON, wrong shape. Coercing it would hide a prompt problem.
        $this->expectException(InvalidAiResponseException::class);

        $parser->parse('[{"ok":true}]');
    }

    public function test_the_parser_rejects_a_bare_scalar(): void
    {
        $parser = app(StructuredResponseParser::class);

        $this->expectException(InvalidAiResponseException::class);

        $parser->parse('"just a string"');
    }

    public function test_try_parse_returns_null_instead_of_throwing(): void
    {
        $parser = app(StructuredResponseParser::class);

        $this->assertNull($parser->tryParse('not json at all'));
        $this->assertSame(['ok' => true], $parser->tryParse('{"ok":true}'));
    }
}
