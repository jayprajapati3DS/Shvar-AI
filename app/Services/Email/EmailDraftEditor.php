<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\ActivityType;
use App\Enums\EmailDraftStatus;
use App\Models\Activity;
use App\Models\EmailDraft;
use App\Models\EmailDraftVersion;
use App\Models\EmailGeneration;
use App\Services\Email\Exceptions\EmailNotApprovedException;
use App\Services\Email\Exceptions\RecipientNotAllowedException;
use Illuminate\Support\Facades\DB;

/**
 * Owns every state change a draft can go through.
 *
 * Gathered into one class rather than spread across controllers because the
 * transitions are the part of this workflow that must not drift: approve() is
 * the only thing anywhere that sets approved_at, and send() is the only thing
 * that sets sent_at - and it refuses a draft that is not approved, whichever
 * transport is configured.
 *
 * Every edit appends a version rather than replacing one, so the model's
 * original wording survives however many rewrites follow it.
 */
class EmailDraftEditor
{
    public function __construct(
        private readonly EmailQualityChecker $quality,
        private readonly EmailServiceInterface $mailer,
    ) {}

    /**
     * Save an edited subject and body.
     *
     * Moves the draft to User Edited only when the text actually changed -
     * opening the editor, touching nothing and clicking Save should not
     * relabel an untouched AI draft as something the user wrote.
     */
    public function saveEdit(EmailDraft $draft, string $subject, string $body): EmailDraft
    {
        $subject = trim($subject);
        $body = rtrim($body);

        $unchanged = $subject === $draft->subject && $body === $draft->body;

        if ($unchanged) {
            // Still refresh the checks: the signature or length setting may
            // have changed since this draft was generated.
            $draft->update(['quality' => $this->quality->check($draft)]);

            return $draft;
        }

        return DB::transaction(function () use ($draft, $subject, $body): EmailDraft {
            // Derived from the versions table rather than the draft's own
            // column: that table owns the numbering, and it carries a unique
            // constraint that a stale in-memory counter would collide with.
            $version = ((int) $draft->versions()->max('version')) + 1;

            $draft->update([
                'subject' => $subject,
                'body' => $body,
                'status' => EmailDraftStatus::UserEdited,
                'version' => $version,
                'word_count' => EmailDraft::countWords($body),

                // An edit invalidates a previous approval. Approving text and
                // then changing it must not leave the changed text approved.
                'approved_at' => null,
            ]);

            EmailDraftVersion::create([
                'email_draft_id' => $draft->id,
                'version' => $version,
                'subject' => $subject,
                'body' => $body,
                'source' => EmailDraftVersion::SOURCE_USER,
                'word_count' => EmailDraft::countWords($body),
            ]);

            $draft->update(['quality' => $this->quality->check($draft)]);

            return $draft->refresh();
        });
    }

    /**
     * Approve a draft for sending.
     *
     * The only path to Approved, and the only thing that sets approved_at.
     * Blocking quality failures are refused here rather than warned about: a
     * draft with no recipient or an unfilled [First Name] must not become
     * sendable, whatever the user clicked.
     *
     * @return array{approved: bool, reason: string|null}
     */
    public function approve(EmailDraft $draft): array
    {
        if ($draft->status === EmailDraftStatus::Approved) {
            // Idempotent: a retried request must not log a second timeline entry.
            return ['approved' => true, 'reason' => null];
        }

        if (! $draft->isApprovable()) {
            return [
                'approved' => false,
                'reason' => sprintf(
                    'A draft with status "%s" cannot be approved.',
                    $draft->status->value,
                ),
            ];
        }

        $quality = $this->quality->check($draft);

        if ($this->quality->blocks($quality)) {
            $draft->update(['quality' => $quality]);

            return [
                'approved' => false,
                'reason' => 'Fix the blocking issues in the quality check first: '
                    .$this->blockingSummary($quality),
            ];
        }

        DB::transaction(function () use ($draft, $quality): void {
            $draft->update([
                'status' => EmailDraftStatus::Approved,
                'approved_at' => now(),
                'quality' => $quality,
            ]);

            Activity::record(
                $draft->lead,
                ActivityType::Note,
                'Approved outreach email',
                sprintf(
                    '"%s" approved for %s.',
                    $draft->subject,
                    $draft->recipient_email ?? 'the contact',
                ),
            );
        });

        return ['approved' => true, 'reason' => null];
    }

    /**
     * Reject a draft.
     *
     * Kept, not deleted - a rejected draft is useful history and stops the same
     * wording being reconsidered after the next generation.
     */
    public function reject(EmailDraft $draft): EmailDraft
    {
        $draft->update([
            'status' => EmailDraftStatus::Rejected,
            'approved_at' => null,
        ]);

        return $draft;
    }

    /** Move a draft out of the way without judging it. */
    public function archive(EmailDraft $draft): EmailDraft
    {
        $draft->update(['status' => EmailDraftStatus::Archived]);

        return $draft;
    }

    /**
     * Send an approved draft through the configured service.
     *
     * Which service that is depends on EMAIL_DRIVER: 'local' simulates,
     * 'smtp' really sends. This method does not know or care - it asks the
     * same question of both.
     *
     * The approval check happens in three places on purpose - here, in the
     * controller, and inside the transport - because this is the one rule in
     * the whole application where a gap would mean contacting a real person
     * without permission.
     *
     * @return array{sent: bool, reason: string|null, result: EmailSendResult|null}
     */
    public function send(EmailDraft $draft): array
    {
        if (! $draft->isSendable()) {
            return [
                'sent' => false,
                'reason' => 'This email has not been approved yet. Approve it first, then send.',
                'result' => null,
            ];
        }

        try {
            $result = $this->mailer->send($draft);
        } catch (EmailNotApprovedException $e) {
            return ['sent' => false, 'reason' => $e->userMessage(), 'result' => null];
        } catch (RecipientNotAllowedException $e) {
            // The allowlist refusing an address is a guardrail doing its job,
            // not a delivery failure. Marking the draft Failed would be wrong -
            // nothing was attempted and the draft is still perfectly good.
            return ['sent' => false, 'reason' => $e->userMessage(), 'result' => null];
        } catch (\Throwable $e) {
            report($e);

            $draft->update([
                'status' => EmailDraftStatus::Failed,
                'delivery_error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'reason' => 'The send failed. The draft is marked Failed and the error was logged locally.',
                'result' => null,
            ];
        }

        DB::transaction(function () use ($draft, $result): void {
            $draft->update([
                'status' => EmailDraftStatus::Sent,
                'sent_at' => now(),
                'delivery_mode' => $result->mode,
                'delivery_error' => null,
            ]);

            Activity::record(
                $draft->lead,
                ActivityType::Email,
                $result->simulated ? 'Simulated email send' : 'Email sent',
                sprintf(
                    '"%s" to %s.%s',
                    $draft->subject,
                    $result->recipient,
                    $result->simulated
                        ? ' Simulated - nothing left this machine.'
                        : ' Delivered over '.$result->mode.'. This one was real.',
                ),
            );
        });

        return ['sent' => true, 'reason' => null, 'result' => $result];
    }

    /**
     * Resolve a regeneration: keep one run's drafts, archive the other's.
     *
     * Neither set is deleted. "Keep previous" archives the new drafts rather
     * than discarding them, so a regeneration you rejected is still readable
     * later - which is the point of versioning at all.
     */
    public function resolveRegeneration(EmailGeneration $keep, EmailGeneration $discard): void
    {
        DB::transaction(function () use ($discard): void {
            $discard->drafts()
                ->whereIn('status', [
                    EmailDraftStatus::Draft->value,
                    EmailDraftStatus::UserEdited->value,
                ])
                ->update(['status' => EmailDraftStatus::Archived->value]);
        });
    }

    /** @param array{checks: list<array{status: string, label: string, detail: string|null}>} $quality */
    private function blockingSummary(array $quality): string
    {
        $failures = array_filter($quality['checks'], fn (array $c) => $c['status'] === 'fail');

        return implode(' ', array_map(
            fn (array $c) => $c['detail'] ?? $c['label'],
            $failures,
        ));
    }
}
