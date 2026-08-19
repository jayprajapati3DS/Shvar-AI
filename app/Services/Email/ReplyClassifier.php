<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\AiRequestType;
use App\Enums\FollowUpStatus;
use App\Enums\ReplyClassification;
use App\Models\EmailReply;
use App\Models\FollowUpTask;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\PromptLibrary;
use Illuminate\Support\Facades\DB;

/**
 * Reads a reply with the local model and says what it means.
 *
 *   email_replies.body  (from your Outlook, from a CRM contact)
 *        v
 *   PromptLibrary::replyClassification()
 *        v
 *   AIServiceInterface::generateStructured()   <- Ollama on localhost
 *        v
 *   classification + summary + a SUGGESTED follow-up task
 *
 * Two rules it will not break:
 *
 *   1. A suggested task is Open and marked source=ai. Nothing acts on it. It
 *      does not send, schedule or generate anything - it is a reminder with a
 *      date, and you can dismiss it.
 *
 *   2. No follow-up is ever suggested after a refusal. ReplyClassification
 *      answers that structurally through allowsFollowUp(), so it does not
 *      depend on the model behaving. Suggesting "chase them again" to someone
 *      who asked to stop is the one output here that could do real damage.
 */
class ReplyClassifier
{
    public function __construct(
        private readonly AIServiceInterface $ai,
        private readonly PromptLibrary $prompts,
    ) {}

    /**
     * Classify one reply and, where appropriate, suggest a follow-up.
     *
     * @return array{reply: EmailReply, task: FollowUpTask|null}
     *
     * @throws AiException
     */
    public function classify(EmailReply $reply, bool $suggestFollowUp = true): array
    {
        $reply->loadMissing(['lead.company', 'draft']);

        $result = $this->ai->generateStructured(
            $this->prompts->replyClassification(
                $this->replyText($reply),
                $this->context($reply),
                implode(', ', ReplyClassification::values()),
            ),
            $this->schema(),
            AiRequestType::ReplyClassification,
            ['lead_id' => $reply->lead_id],
        );

        $data = $result->data ?? [];

        $classification = ReplyClassification::tryFrom((string) ($data['classification'] ?? ''))
            // An unrecognised label is not a reason to guess. Unclear is a
            // correct answer and it prompts a human to look.
            ?? ReplyClassification::Unclear;

        return DB::transaction(function () use ($reply, $data, $classification, $result, $suggestFollowUp): array {
            $reply->update([
                'classification' => $classification,
                'summary' => $this->text($data['summary'] ?? null),
                'signals' => [
                    'quotes' => $this->verifiedQuotes($data['quotes'] ?? null, $reply),
                    'asks' => $this->strings($data['asks'] ?? null),
                    'mentioned_dates' => $this->strings($data['mentioned_dates'] ?? null),
                ],
                'ai_request_id' => $result->logId,
            ]);

            $task = $suggestFollowUp
                ? $this->suggestTask($reply->refresh(), $classification, $data['follow_up'] ?? null)
                : null;

            return ['reply' => $reply->refresh(), 'task' => $task];
        });
    }

    /**
     * Create the suggested follow-up, if one is warranted.
     *
     * @param  mixed  $followUp  The model's suggestion.
     */
    private function suggestTask(
        EmailReply $reply,
        ReplyClassification $classification,
        mixed $followUp,
    ): ?FollowUpTask {
        // Structural refusal. Not a prompt instruction the model might ignore.
        if (! $classification->allowsFollowUp()) {
            return null;
        }

        if ($reply->lead_id === null) {
            return null;
        }

        $title = is_array($followUp) ? $this->text($followUp['title'] ?? null) : null;

        // An empty title is the model correctly saying "nothing to do here".
        if (blank($title)) {
            return null;
        }

        // Only one AI suggestion per reply, so re-classifying does not pile up
        // duplicate tasks.
        $existing = FollowUpTask::query()
            ->where('email_reply_id', $reply->id)
            ->where('source', FollowUpTask::SOURCE_AI)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $days = is_array($followUp) ? $followUp['wait_days'] ?? null : null;
        $days = is_numeric($days) ? (int) $days : null;

        // The enum's own sense of a decent interval is the fallback, and it
        // also caps the model: nobody wants a suggestion to chase tomorrow
        // someone who said "ask me next quarter".
        $floor = $classification->suggestedDelayDays() ?? 7;
        $days = max($floor, min(180, $days ?? $floor));

        return FollowUpTask::create([
            'lead_id' => $reply->lead_id,
            'email_reply_id' => $reply->id,
            'title' => mb_strimwidth($title, 0, 200, '...'),
            'notes' => is_array($followUp) ? $this->text($followUp['reason'] ?? null) : null,
            'status' => FollowUpStatus::Open,
            'source' => FollowUpTask::SOURCE_AI,
            'due_on' => now()->addDays($days)->toDateString(),
        ]);
    }

    /**
     * The reply text handed to the model.
     *
     * Capped: a long thread carries the entire quoted history below the actual
     * reply, and feeding all of it wastes the context window on text the person
     * did not write this time.
     */
    private function replyText(EmailReply $reply): string
    {
        $body = (string) $reply->body;

        // Trim the quoted original where Outlook marks it. Everything after is
        // our own email coming back at us.
        foreach (["\r\n-----Original Message-----", "\n-----Original Message-----", "\nFrom: "] as $marker) {
            $position = mb_strpos($body, $marker);

            if ($position !== false && $position > 40) {
                $body = mb_substr($body, 0, $position);

                break;
            }
        }

        return mb_strimwidth(trim($body), 0, 6000, "\n\n[...trimmed]");
    }

    /** What this reply is about, so the model can judge the ask against it. */
    private function context(EmailReply $reply): string
    {
        $lines = [
            '=== CONTEXT ===',
            'From: '.($reply->from_name ?? $reply->from_address),
            'Their company: '.($reply->lead?->company?->name ?? '(not recorded)'),
            'Their role: '.($reply->lead?->job_title ?? '(not recorded)'),
            'Subject: '.($reply->subject ?? '(none)'),
        ];

        if ($reply->draft !== null) {
            $lines[] = '';
            $lines[] = 'This appears to answer an email I sent:';
            $lines[] = '  Subject: '.$reply->draft->subject;
            $lines[] = '  About: '.($reply->draft->product?->name ?? '(unknown product)');
        }

        return implode("\n", $lines);
    }

    /**
     * Quotes that actually appear in the reply.
     *
     * The same trick as CompanyResearchValidator: asking for quotes only helps
     * if they are checked. A quote the model composed is exactly the kind of
     * confident detail that makes a wrong classification look justified.
     *
     * @return list<string>
     */
    private function verifiedQuotes(mixed $raw, EmailReply $reply): array
    {
        $haystack = $this->normalise((string) $reply->body);

        return array_values(array_filter(
            $this->strings($raw),
            fn (string $quote) => mb_strlen($quote) >= 8
                && str_contains($haystack, $this->normalise($quote)),
        ));
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(strip_tags($value));

        return $clean === '' ? null : $clean;
    }

    /** @return list<string> */
    private function strings(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $item) {
            $clean = $this->text($item);

            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'classification' => [
                    'type' => 'string',
                    'enum' => ReplyClassification::values(),
                ],
                'summary' => ['type' => 'string'],
                'quotes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'asks' => ['type' => 'array', 'items' => ['type' => 'string']],
                'mentioned_dates' => ['type' => 'array', 'items' => ['type' => 'string']],
                'follow_up' => [
                    'type' => 'object',
                    'properties' => [
                        // Empty means "nothing to do", which is a real answer.
                        'title' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'wait_days' => ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['classification', 'summary'],
        ];
    }
}
