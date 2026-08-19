<?php

declare(strict_types=1);

namespace App\Services\AI\Research;

/**
 * Turns the model's reading of a page into findings safe to show.
 *
 * The important check is groundedness. Every finding claims a verbatim quote
 * from the page; this class goes and looks. If the quote is not in the fetched
 * text, the finding is discarded no matter how plausible it sounds.
 *
 * That is what makes "do not invent facts" enforceable rather than a request.
 * A model answering from memory produces evidence it cannot source, and the
 * check catches exactly that case.
 *
 * Matching is deliberately tolerant of whitespace, case and punctuation - a
 * model will re-wrap a quote or straighten a curly apostrophe without meaning
 * to fabricate. It is not tolerant of different words.
 */
class CompanyResearchValidator
{
    /** Below this, a finding is not worth showing. */
    private const MIN_CONFIDENCE = 0.40;

    /** Shortest quote treated as evidence - anything less proves nothing. */
    private const MIN_EVIDENCE_CHARS = 12;

    /**
     * @param  array<string, mixed>  $payload  Decoded model output.
     * @param  string  $sourceText  The page text the model was given.
     * @return array{
     *     findings: list<array<string, mixed>>,
     *     not_found: list<string>,
     *     page_summary: string|null,
     *     warnings: list<string>
     * }
     */
    public function validate(array $payload, string $sourceText): array
    {
        $haystack = $this->normalise($sourceText);

        $findings = [];
        $warnings = [];
        $seen = [];

        foreach ($this->listOf($payload, 'findings') as $item) {
            if (! is_array($item)) {
                continue;
            }

            $field = $this->string($item['field'] ?? null, 60);

            if ($field === null || ! in_array($field, CompanyResearchSchema::FIELDS, true)) {
                $warnings[] = sprintf(
                    'Ignored a finding for an unknown field (%s).',
                    $field ?? 'unnamed',
                );

                continue;
            }

            // One value per field. A model that reports `industry` twice gets
            // its first, best-evidenced answer.
            if (isset($seen[$field])) {
                $warnings[] = sprintf(
                    'Ignored a second value for %s.',
                    CompanyResearchSchema::label($field),
                );

                continue;
            }

            $value = $this->string($item['value'] ?? null, 4000);

            if ($value === null) {
                continue;
            }

            $evidence = $this->string($item['evidence'] ?? null, 1000);
            $confidence = $this->confidence($item['confidence'] ?? null);

            // THE guard: is the quote actually on the page?
            if ($evidence === null || mb_strlen($evidence) < self::MIN_EVIDENCE_CHARS) {
                $warnings[] = sprintf(
                    'Dropped %s - no supporting quote from the page.',
                    CompanyResearchSchema::label($field),
                );

                continue;
            }

            if (! $this->isGrounded($evidence, $haystack)) {
                $warnings[] = sprintf(
                    'Dropped %s - its quoted evidence does not appear on the page, so it was not stated there.',
                    CompanyResearchSchema::label($field),
                );

                continue;
            }

            if ($confidence < self::MIN_CONFIDENCE) {
                $warnings[] = sprintf(
                    'Dropped %s - too weakly supported (%d%%).',
                    CompanyResearchSchema::label($field),
                    (int) round($confidence * 100),
                );

                continue;
            }

            $seen[$field] = true;

            $findings[] = [
                'field' => $field,
                'label' => CompanyResearchSchema::label($field),
                'value' => $this->formatValue($field, $value),
                'evidence' => $evidence,
                'confidence' => round($confidence, 3),
                'is_list' => in_array($field, CompanyResearchSchema::LIST_FIELDS, true),
            ];
        }

        // Keep the schema's field order so the review list reads consistently.
        usort($findings, fn (array $a, array $b) => array_search($a['field'], CompanyResearchSchema::FIELDS, true)
            <=> array_search($b['field'], CompanyResearchSchema::FIELDS, true));

        return [
            'findings' => $findings,
            'not_found' => $this->notFound($payload, $seen),
            'page_summary' => $this->string($payload['page_summary'] ?? null, 1000),
            'warnings' => $warnings,
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Is this quote really in the page?
     *
     * Three passes, loosening only in ways that cannot change meaning:
     *
     *   1. The normalised quote appears verbatim.
     *   2. It appears with punctuation stripped - covers straightened quotes,
     *      dashes and stray commas.
     *   3. A long enough run of its words appears in order. Covers a model that
     *      quoted across a line break and dropped a word.
     *
     * A quote whose words are simply not on the page fails all three.
     */
    private function isGrounded(string $evidence, string $haystack): bool
    {
        $needle = $this->normalise($evidence);

        if ($needle === '') {
            return false;
        }

        if (str_contains($haystack, $needle)) {
            return true;
        }

        $strippedNeedle = $this->stripPunctuation($needle);
        $strippedHaystack = $this->stripPunctuation($haystack);

        if ($strippedNeedle !== '' && str_contains($strippedHaystack, $strippedNeedle)) {
            return true;
        }

        return $this->hasWordRun($strippedNeedle, $strippedHaystack);
    }

    /**
     * Whether a substantial run of consecutive words from the quote appears in
     * the page, in order.
     *
     * Requires roughly three quarters of the quote to match consecutively, so a
     * quote that merely shares vocabulary with the page does not pass.
     */
    private function hasWordRun(string $needle, string $haystack): bool
    {
        $words = array_values(array_filter(explode(' ', $needle)));
        $total = count($words);

        if ($total < 4) {
            return false;
        }

        $runLength = max(4, (int) ceil($total * 0.75));

        for ($start = 0; $start + $runLength <= $total; $start++) {
            $run = implode(' ', array_slice($words, $start, $runLength));

            if (str_contains($haystack, $run)) {
                return true;
            }
        }

        return false;
    }

    /** Lower-case, collapse whitespace - never changes which words are present. */
    private function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(
            ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}"],
            ["'", "'", '"', '"', '-', '-', ' '],
            $text,
        );

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function stripPunctuation(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Fields the page did not establish.
     *
     * Union of what the model reported and what simply has no finding, so the
     * UI can always tell you what is still missing even if the model omitted
     * `not_found` entirely.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, bool>  $seen
     * @return list<string>
     */
    private function notFound(array $payload, array $seen): array
    {
        $missing = [];

        foreach (CompanyResearchSchema::FIELDS as $field) {
            if (! isset($seen[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /** Lists are stored newline-separated, matching how Product does it. */
    private function formatValue(string $field, string $value): string
    {
        if (! in_array($field, CompanyResearchSchema::LIST_FIELDS, true)) {
            return $value;
        }

        // Models return these as "a, b, c", "a; b", or already newline-split.
        $parts = preg_split('/\s*[\n;,]\s*/u', $value) ?: [$value];

        $parts = array_values(array_filter(
            array_map(trim(...), $parts),
            fn (string $part) => $part !== '',
        ));

        return implode("\n", $parts);
    }

    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            // Absent confidence is not a reason to discard a well-evidenced
            // finding; treat it as "clearly stated but unscored".
            return 0.7;
        }

        $score = (float) $value;

        if ($score > 1.0) {
            $score /= 100;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function listOf(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (is_array($value)) {
            // A list value arrives as an array often enough to be worth joining
            // rather than discarding.
            $value = implode(', ', array_filter($value, is_scalar(...)));
        }

        if (is_object($value) || is_bool($value)) {
            return null;
        }

        $string = trim((string) ($value ?? ''));

        if ($string === '' || strcasecmp($string, 'null') === 0) {
            return null;
        }

        return mb_substr($string, 0, $limit);
    }
}
