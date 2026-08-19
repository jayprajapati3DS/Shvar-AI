<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailVariant;
use App\Models\LeadProductMatch;
use App\Services\AI\Exceptions\InvalidAiResponseException;

/**
 * Turns whatever the model returned into something safe to store.
 *
 * The schema constrains the SHAPE of the response. This constrains its
 * CONTENT, which is where the real risks are: an email addressed to
 * "[First Name]", a sign-off the model wrote itself over the top of the real
 * signature, a subject line lifted into the body, an HTML tag, or a confident
 * claim about a product capability that appears nowhere in the product record.
 *
 * Nothing here silently improves the model's writing. Repairs are limited to
 * removing what must not be sent (markup, duplicated sign-offs, a stray subject
 * line) and everything removed or doubted is reported in warnings so the UI can
 * say the output was filtered rather than pretending it arrived clean.
 *
 * A variant that cannot be repaired into something sendable is dropped. If none
 * survive, that is a failed generation, not a draft with an empty body.
 */
class EmailValidator
{
    /** Placeholders a model leaves behind when it lacks a value. */
    private const PLACEHOLDER_PATTERN = '/\[[^\]\n]{1,40}\]|\{\{[^}\n]{1,40}\}\}|<[A-Z][A-Z _]{2,30}>/u';

    /**
     * Openers the brief bans outright, plus the ones a model reaches for when
     * asked to personalise.
     *
     * The "research framing" group is the subtle one. "I have been reviewing
     * your work with X" may state a perfectly true fact from the record, but it
     * asserts an activity that never happened - the model read a database row.
     * Section 11: never pretend research was performed.
     */
    private const BANNED_PHRASES = [
        'i hope this email finds you well',
        'i hope this finds you well',
        'hope you are doing well',
        'we are a leading',
        'as a leading provider',
        'cutting-edge',
        'game-changing',
        'revolutionary',
        'world-class',
        'best-in-class',

        // Research framing - claims an act of looking that did not occur.
        'i came across',
        'i noticed',
        'i have been reviewing',
        "i've been reviewing",
        'i have been following',
        "i've been following",
        'having looked at',
        'after reviewing your',
        'i see that you',
    ];

    /**
     * Lines that look like a signature the model wrote for itself.
     *
     * The application appends the real one, so a model-written block would
     * either duplicate it or - worse - state a job title and phone number it
     * invented.
     */
    private const SIGNOFF_PATTERN = '/^\s*(best regards|kind regards|regards|sincerely|warm regards|'
        .'many thanks|thanks|thank you|yours (sincerely|faithfully))\s*,?\s*$/iu';

    /**
     * Validate and clean one AI response.
     *
     * @param  array<string, mixed>  $data  Decoded model output.
     * @return array{
     *     variants: list<array{variant: EmailVariant, subject: string, body: string, word_count: int}>,
     *     personalization_points: list<string>,
     *     claims_used: list<string>,
     *     missing_information: list<string>,
     *     warnings: list<string>
     * }
     *
     * @throws InvalidAiResponseException when nothing usable survives.
     */
    public function validate(array $data, LeadProductMatch $recommendation): array
    {
        $warnings = [];

        $variants = $this->variants($data['variants'] ?? null, $warnings);

        if ($variants === []) {
            throw new InvalidAiResponseException(
                'The local model returned no usable email. Every variant was empty, '
                .'malformed, or contained unfilled placeholders.',
                structured: true,
            );
        }

        $claims = $this->strings($data['claims_used'] ?? null);

        return [
            'variants' => $variants,
            'personalization_points' => $this->strings($data['personalization_points'] ?? null),
            'claims_used' => $claims,
            'missing_information' => $this->strings($data['missing_information'] ?? null),
            'warnings' => [
                ...$warnings,
                ...$this->unsupportedClaims($claims, $recommendation),
            ],
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Variants */
    /* ---------------------------------------------------------------------- */

    /**
     * @param  list<string>  $warnings
     * @return list<array{variant: EmailVariant, subject: string, body: string, word_count: int}>
     */
    private function variants(mixed $raw, array &$warnings): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $accepted = [];
        $seen = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $variant = EmailVariant::tryFrom((string) ($item['style'] ?? ''));

            if ($variant === null) {
                $warnings[] = sprintf(
                    'Dropped a variant with an unrecognised style "%s".',
                    (string) ($item['style'] ?? ''),
                );

                continue;
            }

            // A model that returns the same style twice has misread the task;
            // keeping both would show the user two things labelled identically.
            if (isset($seen[$variant->value])) {
                $warnings[] = "Dropped a duplicate {$variant->shortLabel()} variant.";

                continue;
            }

            $subject = $this->cleanSubject((string) ($item['subject'] ?? ''), $variant, $warnings);
            $body = $this->cleanBody((string) ($item['body'] ?? ''), $variant, $warnings);

            if ($subject === '' || $body === '') {
                $warnings[] = "Dropped the {$variant->shortLabel()} variant: it had no usable "
                    .($subject === '' ? 'subject' : 'body').'.';

                continue;
            }

            $seen[$variant->value] = true;

            $accepted[] = [
                'variant' => $variant,
                'subject' => $subject,
                'body' => $body,
                'word_count' => count(preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            ];
        }

        return $accepted;
    }

    /** @param list<string> $warnings */
    private function cleanSubject(string $subject, EmailVariant $variant, array &$warnings): string
    {
        $subject = $this->stripMarkup($subject);

        // Models like to prefix the line they were asked for.
        $subject = preg_replace('/^\s*subject\s*:\s*/i', '', $subject) ?? $subject;
        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);

        if ($this->hasPlaceholder($subject)) {
            $warnings[] = "The {$variant->shortLabel()} subject contained an unfilled placeholder.";

            return '';
        }

        // Long enough to be a sentence fragment rather than a word, short
        // enough that a mail client will not truncate it into nonsense.
        return mb_strlen($subject) > 150 ? mb_substr($subject, 0, 150) : $subject;
    }

    /** @param list<string> $warnings */
    private function cleanBody(string $body, EmailVariant $variant, array &$warnings): string
    {
        $body = $this->stripMarkup($body);
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        $lines = explode("\n", $body);

        // Drop a subject line the model put inside the body despite being told
        // not to - it would be sent to the recipient as the first line of text.
        while ($lines !== [] && preg_match('/^\s*subject\s*:/i', $lines[0]) === 1) {
            array_shift($lines);
            $warnings[] = "Removed a subject line from inside the {$variant->shortLabel()} body.";
        }

        $lines = $this->trimSignature($lines, $variant, $warnings);

        $body = trim(implode("\n", $lines));

        // Collapse runs of blank lines; a model that pads paragraphs produces
        // an email that looks broken in a mail client.
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        if ($this->hasPlaceholder($body)) {
            $warnings[] = "The {$variant->shortLabel()} body contained an unfilled placeholder "
                .'such as [Company] or [First Name].';

            return '';
        }

        foreach (self::BANNED_PHRASES as $phrase) {
            if (str_contains(mb_strtolower($body), $phrase)) {
                // Reported, not removed: cutting the sentence out would leave a
                // gap the user cannot see. Better to flag it for review.
                $warnings[] = sprintf(
                    'The %s body uses a phrase the style rules ban: "%s".',
                    $variant->shortLabel(),
                    $phrase,
                );
            }
        }

        return $body;
    }

    /**
     * Remove a signature block the model wrote for itself.
     *
     * Everything from the sign-off line onwards goes, and the sign-off is put
     * back as the last line. The application appends the real signature after
     * it, so what the model invented for a job title or phone number never
     * reaches anyone.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function trimSignature(array $lines, EmailVariant $variant, array &$warnings): array
    {
        foreach ($lines as $index => $line) {
            if (preg_match(self::SIGNOFF_PATTERN, $line) !== 1) {
                continue;
            }

            $trailing = array_filter(
                array_slice($lines, $index + 1),
                fn (string $l) => trim($l) !== '',
            );

            if ($trailing !== []) {
                $warnings[] = 'Removed a signature block the model wrote at the end of the '
                    ."{$variant->shortLabel()} body. Your configured signature is used instead.";
            }

            return [...array_slice($lines, 0, $index), rtrim($line)];
        }

        return $lines;
    }

    /* ---------------------------------------------------------------------- */
    /* Claims */
    /* ---------------------------------------------------------------------- */

    /**
     * Flag product claims that do not trace back to the product record.
     *
     * Section 27 of the brief: the products table is the source of truth. The
     * check is deliberately generous - it looks for meaningful word overlap
     * rather than exact quotation, because the model is asked to paraphrase and
     * demanding verbatim text would flag every honest claim.
     *
     * It is a warning, never a block. A false positive that silently deleted a
     * true sentence would be worse than one the user glances at and dismisses.
     *
     * @param  list<string>  $claims
     * @return list<string>
     */
    private function unsupportedClaims(array $claims, LeadProductMatch $recommendation): array
    {
        if ($claims === []) {
            // Not "nothing to check" - "the check did not run". A model that
            // skips claims_used produces an email full of product statements
            // and an empty audit of them, which looks identical to a clean
            // result unless it is said out loud. Observed on qwen3:4b before
            // the key was made required in the schema.
            return [
                'The model did not report which product claims it made, so none could be checked '
                .'against the product record. Read the emails carefully before approving.',
            ];
        }

        $recommendation->loadMissing('product');
        $product = $recommendation->product;

        $source = mb_strtolower(implode(' ', array_filter([
            $product?->name,
            $product?->category,
            $product?->short_description,
            $product?->detailed_description,
            $product?->key_features,
            $product?->target_customer,
            $product?->target_specialty,
            $product?->value_proposition,
            $recommendation->reason,
            $recommendation->sales_angle,
            $recommendation->suggested_use_case,
            $recommendation->module,
        ])));

        if (trim($source) === '') {
            return ['The product record holds no description, so no product claim in this email could be checked.'];
        }

        $sourceWords = $this->significantWords($source);
        $warnings = [];

        foreach ($claims as $claim) {
            $claimWords = $this->significantWords(mb_strtolower($claim));

            if ($claimWords === []) {
                continue;
            }

            $supported = count(array_intersect($claimWords, $sourceWords));

            // Under half the meaningful words appearing anywhere in the product
            // record means the claim is largely made of terms the record never
            // used - which is what a fabricated capability looks like.
            if ($supported / count($claimWords) < 0.5) {
                $warnings[] = sprintf(
                    'Check this claim against the product record - most of it does not appear there: "%s"',
                    mb_strimwidth($claim, 0, 160, '…'),
                );
            }
        }

        return $warnings;
    }

    /**
     * Words worth comparing: no stopwords, nothing under four characters.
     *
     * @return list<string>
     */
    private function significantWords(string $text): array
    {
        $stopwords = [
            'that', 'this', 'with', 'from', 'into', 'their', 'there', 'which', 'while',
            'have', 'has', 'had', 'been', 'being', 'they', 'them', 'your', 'our', 'and',
            'the', 'for', 'are', 'can', 'will', 'would', 'could', 'should', 'also',
            'such', 'more', 'most', 'than', 'then', 'when', 'what', 'where', 'both',
            'supports', 'support', 'provides', 'provide', 'offers', 'offer', 'allows', 'allow',
            'enables', 'enable', 'helps', 'help', 'used', 'using', 'use',
        ];

        $words = preg_split('/[^a-z0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w) => mb_strlen($w) >= 4 && ! in_array($w, $stopwords, true),
        )));
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Strip anything that could execute or render.
     *
     * Plain text is the only format Phase 4 produces, so markup in the model's
     * output is never wanted - and stripping it here means no downstream
     * consumer has to remember to escape it.
     */
    private function stripMarkup(string $value): string
    {
        // Tags first, then any entity they left behind.
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // A second pass: decoding can reveal a tag that was entity-encoded.
        return strip_tags($value);
    }

    private function hasPlaceholder(string $value): bool
    {
        return preg_match(self::PLACEHOLDER_PATTERN, $value) === 1;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $clean = trim($this->stripMarkup((string) $item));

            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return array_values(array_unique($out));
    }
}
