<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailLength;
use App\Models\EmailDraft;

/**
 * The pre-approval checklist.
 *
 * Deliberately rule-based and local: no second AI call, no judgement about
 * whether the writing is good. It answers questions with objectively correct
 * answers - is there a recipient, is there a greeting, is there an ask, is
 * there a placeholder nobody filled in.
 *
 * Two severities and no more:
 *
 *   fail  something that must not go to a real person. Blocks approval.
 *   warn  worth a look. Never blocks - a checker that stops you sending a
 *         perfectly good 190-word email is a checker you learn to click past.
 *
 * Recomputed on every save, so the panel never shows a verdict about text that
 * has since been edited.
 */
class EmailQualityChecker
{
    /** Placeholder syntax the validator also looks for, re-checked after edits. */
    private const PLACEHOLDER_PATTERN = '/\[[^\]\n]{1,40}\]|\{\{[^}\n]{1,40}\}\}/u';

    /** Phrases that betray the model talking about the task, not to the reader. */
    private const META_PHRASES = [
        'as an ai',
        'as a language model',
        'here is the email',
        'here is a draft',
        'i have written',
        'draft email',
        'certainly!',
        'sure!',
        'i hope this helps',
        'let me know if you would like me to',
        'feel free to adjust',
        'below is',
    ];

    /** Openings that count as a greeting. */
    private const GREETING_PATTERN = '/^\s*(hi|hello|dear|good (morning|afternoon|evening))\b/iu';

    /**
     * Wording that constitutes an ask.
     *
     * Broad on purpose: a false "no call to action" on an email that plainly
     * has one trains the user to ignore the panel.
     */
    private const CTA_PATTERN = '/\b(would you be|are you|do you|happy to|glad to|worth a|open to|'
        .'let me know|get in touch|reply|respond|arrange|schedule|book|share|send (you|over)|'
        .'brief (call|chat|discussion|overview)|short (call|chat|discussion|overview)|'
        .'interested|explore|discuss|connect)\b/iu';

    public function __construct(
        private readonly EmailSettings $settings,
    ) {}

    /**
     * Run every check against a draft's current text.
     *
     * @return array{
     *     passed: bool,
     *     blocking: bool,
     *     word_count: int,
     *     checks: list<array{key: string, label: string, status: string, detail: string|null}>
     * }
     */
    public function check(EmailDraft $draft, ?EmailLength $length = null): array
    {
        $draft->loadMissing(['lead', 'product']);

        $body = (string) $draft->body;
        $subject = (string) $draft->subject;
        $words = EmailDraft::countWords($body);

        $length ??= $this->settings->length();

        $checks = [
            $this->recipient($draft),
            $this->subjectPresent($subject),
            $this->bodyPresent($body),
            $this->product($draft),
            $this->placeholders($subject, $body),
            $this->greeting($body),
            $this->callToAction($body),
            $this->metaLanguage($body),
            $this->length($words, $length),
            $this->signature(),
        ];

        return [
            'passed' => ! $this->any($checks, ['fail', 'warn']),
            'blocking' => $this->any($checks, ['fail']),
            'word_count' => $words,
            'checks' => $checks,
        ];
    }

    /**
     * Whether a draft may be approved.
     *
     * Only a `fail` stands in the way. Section 26: minor issues must not block.
     *
     * @param  array{blocking: bool}  $result
     */
    public function blocks(array $result): bool
    {
        return $result['blocking'];
    }

    /* ---------------------------------------------------------------------- */
    /* Individual checks */
    /* ---------------------------------------------------------------------- */

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function recipient(EmailDraft $draft): array
    {
        $email = $draft->recipient_email ?? $draft->lead?->email;

        if (blank($email)) {
            return $this->fail('recipient', 'Recipient', 'No email address on this contact.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail('recipient', 'Recipient', "\"{$email}\" is not a valid email address.");
        }

        return $this->pass('recipient', 'Recipient', $email);
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function subjectPresent(string $subject): array
    {
        if (trim($subject) === '') {
            return $this->fail('subject', 'Subject line', 'The subject is empty.');
        }

        if (mb_strlen($subject) > 90) {
            return $this->warn('subject', 'Subject line', 'Long enough that some clients will truncate it.');
        }

        return $this->pass('subject', 'Subject line', null);
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function bodyPresent(string $body): array
    {
        return trim($body) === ''
            ? $this->fail('body', 'Email body', 'The body is empty.')
            : $this->pass('body', 'Email body', null);
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function product(EmailDraft $draft): array
    {
        if ($draft->product === null) {
            return $this->warn(
                'product',
                'Product',
                'The product this email was written about has been deleted.',
            );
        }

        return $this->pass('product', 'Product', $draft->product->name);
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function placeholders(string $subject, string $body): array
    {
        // A placeholder is the one cosmetic-looking problem that is genuinely
        // unsendable: "Hi [First Name]," tells the recipient exactly how much
        // attention they were worth.
        if (preg_match(self::PLACEHOLDER_PATTERN, $subject.' '.$body, $match) === 1) {
            return $this->fail('placeholders', 'No placeholders', "Still contains {$match[0]}.");
        }

        return $this->pass('placeholders', 'No placeholders', null);
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function greeting(string $body): array
    {
        return preg_match(self::GREETING_PATTERN, ltrim($body)) === 1
            ? $this->pass('greeting', 'Greeting', null)
            : $this->warn('greeting', 'Greeting', 'The email does not open with a greeting.');
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function callToAction(string $body): array
    {
        return preg_match(self::CTA_PATTERN, $body) === 1
            ? $this->pass('call_to_action', 'Call to action', null)
            : $this->warn('call_to_action', 'Call to action', 'No clear ask - what should they do next?');
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function metaLanguage(string $body): array
    {
        $lower = mb_strtolower($body);

        foreach (self::META_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return $this->warn(
                    'meta_language',
                    'No AI meta-language',
                    "Contains \"{$phrase}\" - the model talking about the task rather than to the reader.",
                );
            }
        }

        return $this->pass('meta_language', 'No AI meta-language', null);
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function length(int $words, EmailLength $length): array
    {
        [$min, $max] = $length->range();

        if ($words < $min) {
            return $this->warn('length', 'Length', "{$words} words - shorter than your {$length->value} setting.");
        }

        // Generous overshoot before complaining: the band is a target, and an
        // email 10 words over is not a problem worth a warning.
        if ($words > $max * 1.25) {
            return $this->warn('length', 'Length', "{$words} words - well over your {$length->value} setting.");
        }

        return $this->pass('length', 'Length', "{$words} words");
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function signature(): array
    {
        return $this->settings->isConfigured()
            ? $this->pass('signature', 'Signature', null)
            : $this->warn(
                'signature',
                'Signature',
                'No signature configured - the email will be sent unsigned. Set one in Settings.',
            );
    }

    /* ---------------------------------------------------------------------- */

    /**
     * @param  list<array{status: string}>  $checks
     * @param  list<string>  $statuses
     */
    private function any(array $checks, array $statuses): bool
    {
        foreach ($checks as $check) {
            if (in_array($check['status'], $statuses, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function pass(string $key, string $label, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => 'pass', 'detail' => $detail];
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function warn(string $key, string $label, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => 'warn', 'detail' => $detail];
    }

    /** @return array{key: string, label: string, status: string, detail: string|null} */
    private function fail(string $key, string $label, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => 'fail', 'detail' => $detail];
    }
}
