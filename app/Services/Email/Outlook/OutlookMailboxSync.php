<?php

declare(strict_types=1);

namespace App\Services\Email\Outlook;

use App\Enums\EmailDraftStatus;
use App\Models\Contact;
use App\Models\EmailDraft;
use App\Models\EmailReply;
use Illuminate\Support\Facades\DB;

/**
 * Imports replies from Outlook into email_replies.
 *
 * THIS CLASS OWNS THE PRIVACY SCOPE, and it is the narrowest part of the whole
 * feature by design. The mailbox on this machine holds thousands of messages
 * that have nothing to do with the CRM - personal mail, internal threads,
 * newsletters. None of them are opened.
 *
 * The rule, enforced here rather than left to the gateway: a message is read
 * ONLY if its sender address matches a Contact record. The address list is
 * built from the contacts table and handed to the gateway, which filters
 * against it before touching a body. Nothing else is stored, shown or sent to
 * the model.
 *
 * The sync is idempotent. outlook_entry_id is unique, so running it twice
 * imports nothing twice, and a message already imported is never re-read.
 */
class OutlookMailboxSync
{
    /** How far back a first sync looks. */
    public const DEFAULT_DAYS = 30;

    /** Hard cap per run, so a first sync on a busy mailbox cannot hang. */
    public const MAX_PER_RUN = 100;

    public function __construct(
        private readonly OutlookGatewayInterface $outlook,
    ) {}

    /**
     * Import anything new from CRM contacts.
     *
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     contacts: int,
     *     replies: list<EmailReply>,
     *     message: string
     * }
     *
     * @throws OutlookException
     */
    public function sync(?int $days = null, ?int $limit = null): array
    {
        $days ??= self::DEFAULT_DAYS;
        $limit ??= self::MAX_PER_RUN;

        // The scope, built from the CRM and nothing else.
        $contacts = $this->contactsByAddress();

        if ($contacts === []) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'contacts' => 0,
                'replies' => [],
                'message' => 'No contacts have an email address, so there is nothing to look for.',
            ];
        }

        $messages = $this->outlook->inboxFrom(
            array_keys($contacts),
            now()->subDays($days)->toDateTimeImmutable(),
            $limit,
        );

        $imported = [];
        $skipped = 0;

        foreach ($messages as $message) {
            $entryId = (string) $message['entry_id'];

            // Idempotent: already imported means already read.
            if (EmailReply::where('outlook_entry_id', $entryId)->exists()) {
                $skipped++;

                continue;
            }

            $contact = $contacts[mb_strtolower((string) $message['from_address'])] ?? null;

            if ($contact === null) {
                // The gateway should have filtered this out. If it did not,
                // refuse it here rather than storing mail from outside scope.
                $skipped++;

                continue;
            }

            $imported[] = $this->store($message, $contact);
        }

        return [
            'imported' => count($imported),
            'skipped' => $skipped,
            'contacts' => count($contacts),
            'replies' => $imported,
            'message' => $this->summarise(count($imported), $skipped, count($contacts), $days),
        ];
    }

    /**
     * Move drafts handed to Outlook from Queued to Sent, once they really went.
     *
     * A draft becomes Queued when its compose window opens. Whether the user
     * then pressed Send or closed the window is not knowable from here - only
     * Outlook knows, and it says so through the item's SentOn timestamp.
     *
     * @return int drafts promoted
     */
    public function reconcileQueued(): int
    {
        $queued = EmailDraft::query()
            ->where('status', EmailDraftStatus::Queued->value)
            ->whereNotNull('delivery_reference')
            ->get();

        $promoted = 0;

        foreach ($queued as $draft) {
            if (! $this->outlook->wasSent((string) $draft->delivery_reference)) {
                continue;
            }

            $draft->update([
                'status' => EmailDraftStatus::Sent,
                'sent_at' => now(),
            ]);

            $promoted++;
        }

        return $promoted;
    }

    /* ---------------------------------------------------------------------- */

    /**
     * Every CRM contact that has an address, keyed by lowercased address.
     *
     * @return array<string, Contact>
     */
    private function contactsByAddress(): array
    {
        $map = [];

        Contact::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->with(['leads' => fn ($q) => $q->latest('updated_at')->limit(1)])
            ->chunk(200, function ($chunk) use (&$map): void {
                foreach ($chunk as $contact) {
                    $map[mb_strtolower((string) $contact->email)] = $contact;
                }
            });

        return $map;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function store(array $message, Contact $contact): EmailReply
    {
        return DB::transaction(function () use ($message, $contact): EmailReply {
            $draft = $this->matchDraft($message, $contact);

            return EmailReply::create([
                'outlook_entry_id' => (string) $message['entry_id'],
                'conversation_id' => $message['conversation_id'] ?? null,
                'contact_id' => $contact->id,

                // The lead this concerns: the one the matched draft belongs to,
                // else the contact's most recently touched lead.
                'lead_id' => $draft?->lead_id ?? $contact->leads->first()?->id,

                'email_draft_id' => $draft?->id,
                'from_address' => (string) $message['from_address'],
                'from_name' => $message['from_name'] ?? null,
                'subject' => $message['subject'] ?? null,
                'body' => $message['body'] ?? null,
                'received_at' => $message['received_at'],
            ]);
        });
    }

    /**
     * The draft this message appears to answer.
     *
     * Conversation id first - Outlook threads a reply to its original, and that
     * is far more reliable than anything derived from the subject. Falling back
     * to "the most recent thing we sent this person before it arrived", which
     * is usually right and is only ever used to attach context, never to decide
     * anything.
     *
     * @param  array<string, mixed>  $message
     */
    private function matchDraft(array $message, Contact $contact): ?EmailDraft
    {
        $conversationId = $message['conversation_id'] ?? null;

        if (filled($conversationId)) {
            $byConversation = EmailDraft::query()
                ->where('contact_id', $contact->id)
                ->where('delivery_reference', $conversationId)
                ->latestFirst()
                ->first();

            if ($byConversation !== null) {
                return $byConversation;
            }
        }

        return EmailDraft::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', [
                EmailDraftStatus::Sent->value,
                EmailDraftStatus::Queued->value,
            ])
            ->where(fn ($q) => $q
                ->whereNull('sent_at')
                ->orWhere('sent_at', '<=', $message['received_at']))
            ->latestFirst()
            ->first();
    }

    private function summarise(int $imported, int $skipped, int $contacts, int $days): string
    {
        if ($imported === 0) {
            return sprintf(
                'No new replies in the last %d days from your %d contact(s).%s',
                $days,
                $contacts,
                $skipped > 0 ? " {$skipped} already imported." : '',
            );
        }

        return sprintf(
            'Imported %d repl%s from your contacts.%s Nothing else in your mailbox was read.',
            $imported,
            $imported === 1 ? 'y' : 'ies',
            $skipped > 0 ? " {$skipped} already imported." : '',
        );
    }
}
