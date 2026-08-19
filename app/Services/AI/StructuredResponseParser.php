<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Exceptions\InvalidAiResponseException;

/**
 * Turns a model's text into a validated associative array.
 *
 * The primary defence is asking Ollama for JSON in the first place (`format`),
 * which constrains generation rather than hoping. This class handles what is
 * left over, in a strict order:
 *
 *   1. Decode the whole string. Works when the model behaved.
 *   2. Strip a ```json fence, then decode. Small models add fences constantly.
 *   3. Extract the outermost balanced {...} and decode that. Catches a model
 *      that prefixed "Sure, here is the JSON:".
 *
 * Step 3 is brace-*balanced* scanning that respects strings and escapes - not a
 * regex - so a `}` inside a string value cannot end the object early.
 *
 * Anything still undecodable raises InvalidAiResponseException. It never returns
 * a partial or guessed structure: a half-parsed product recommendation is worse
 * than an honest failure.
 */
class StructuredResponseParser
{
    /**
     * @param  array<string, mixed>  $schema  Optional JSON Schema; `required` keys are enforced.
     * @return array<string, mixed>
     *
     * @throws InvalidAiResponseException
     */
    public function parse(string $raw, array $schema = []): array
    {
        $decoded = $this->decodeWhole($raw)
            ?? $this->decodeFenced($raw)
            ?? $this->decodeExtracted($raw);

        if ($decoded === null) {
            throw new InvalidAiResponseException(
                'Could not decode JSON from model output: '.$this->excerpt($raw),
                structured: true,
            );
        }

        // A bare scalar or a top-level array is valid JSON but not the object
        // shape every caller expects, so reject it rather than coercing.
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidAiResponseException(
                'Model returned '.gettype($decoded).', expected a JSON object: '.$this->excerpt($raw),
                structured: true,
            );
        }

        $this->assertRequiredKeys($decoded, $schema, $raw);

        return $decoded;
    }

    /**
     * Non-throwing variant, for callers that can tolerate unstructured output.
     *
     * @return array<string, mixed>|null
     */
    public function tryParse(string $raw, array $schema = []): ?array
    {
        try {
            return $this->parse($raw, $schema);
        } catch (InvalidAiResponseException) {
            return null;
        }
    }

    private function decodeWhole(string $raw): mixed
    {
        return $this->jsonDecode(trim($raw));
    }

    /** Handles ```json … ``` and bare ``` … ``` fences. */
    private function decodeFenced(string $raw): mixed
    {
        if (! preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $matches)) {
            return null;
        }

        return $this->jsonDecode(trim($matches[1]));
    }

    /** Outermost balanced object, ignoring braces inside string literals. */
    private function decodeExtracted(string $raw): mixed
    {
        $start = strpos($raw, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $this->jsonDecode(substr($raw, $start, $i - $start + 1));
                }
            }
        }

        return null;
    }

    private function jsonDecode(string $candidate): mixed
    {
        if ($candidate === '') {
            return null;
        }

        $decoded = json_decode($candidate, true, 64);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $schema
     *
     * @throws InvalidAiResponseException
     */
    private function assertRequiredKeys(array $decoded, array $schema, string $raw): void
    {
        $required = $schema['required'] ?? null;

        if (! is_array($required) || $required === []) {
            return;
        }

        $missing = array_values(array_diff($required, array_keys($decoded)));

        if ($missing !== []) {
            throw new InvalidAiResponseException(
                'Structured response is missing required key(s): '.implode(', ', $missing)
                .'. Got: '.$this->excerpt($raw),
                structured: true,
            );
        }
    }

    /** Keeps the technical log message readable when a model rambles. */
    private function excerpt(string $raw, int $length = 300): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);

        return mb_strlen($clean) <= $length
            ? $clean
            : mb_substr($clean, 0, $length).'…';
    }
}
