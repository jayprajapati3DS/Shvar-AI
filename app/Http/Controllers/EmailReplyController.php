<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReplyClassification;
use App\Models\EmailReply;
use App\Services\AI\Exceptions\AiException;
use App\Services\Email\Outlook\OutlookException;
use App\Services\Email\Outlook\OutlookMailboxSync;
use App\Services\Email\ReplyClassifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Replies received in Outlook from CRM contacts.
 *
 * The sync is a deliberate action, not a background poll. Reading someone's
 * mailbox should be something they asked for and can see happening, and a timer
 * quietly opening mail is a different thing from a button that says what it did.
 */
class EmailReplyController extends Controller
{
    public function __construct(
        private readonly OutlookMailboxSync $sync,
        private readonly ReplyClassifier $classifier,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['classification', 'reviewed']);

        $replies = EmailReply::query()
            ->with([
                'lead',
                'lead:id,company_id,lead_status',
                'draft:id,subject',
                'followUpTasks',
            ])
            ->filter($filters)
            ->latestFirst()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Replies/Index', [
            'replies' => $replies->through(fn (EmailReply $r) => $this->present($r)),
            'filters' => $filters,
            'filterOptions' => [
                'classifications' => ReplyClassification::options(),
            ],
            'counts' => [
                'unreviewed' => EmailReply::unreviewed()->count(),
                'needing_attention' => EmailReply::needingAttention()->count(),
            ],
        ]);
    }

    /**
     * Read new replies from Outlook.
     *
     * Only messages whose sender matches a Contact record are touched -
     * OutlookMailboxSync owns that scope and the message says so, because "we
     * read your inbox" deserves to be stated rather than assumed.
     */
    public function store(): RedirectResponse
    {
        try {
            $result = $this->sync->sync();
            $promoted = $this->sync->reconcileQueued();
        } catch (OutlookException $e) {
            return back()->with('error', trim($e->userMessage().' '.($e->hint() ?? '')));
        }

        $message = $result['message'];

        if ($promoted > 0) {
            $message .= sprintf(
                ' %d draft(s) you sent from Outlook are now marked Sent.',
                $promoted,
            );
        }

        return back()->with($result['imported'] > 0 ? 'success' : 'info', $message);
    }

    /** Have the local model read one reply and suggest what to do. */
    public function classify(EmailReply $reply): RedirectResponse
    {
        try {
            $outcome = $this->classifier->classify($reply);
        } catch (AiException $e) {
            return back()->with('error', trim($e->userMessage().' '.($e->hint() ?? '')));
        }

        $classification = $outcome['reply']->classification;

        return back()->with('success', sprintf(
            'Read as "%s".%s',
            $classification?->value ?? 'Unclear',
            $outcome['task'] !== null
                ? ' A follow-up was suggested - review it before relying on it.'
                : ' No follow-up suggested.',
        ));
    }

    /** Mark a reply as dealt with. */
    public function review(EmailReply $reply): RedirectResponse
    {
        $reply->update(['reviewed_at' => now()]);

        return back()->with('success', 'Marked as reviewed.');
    }

    public function destroy(EmailReply $reply): RedirectResponse
    {
        // Deletes the local copy only. The message stays in Outlook.
        $reply->delete();

        return back()->with('success', 'Removed from Shvar. The email is untouched in Outlook.');
    }

    /** @return array<string, mixed> */
    private function present(EmailReply $reply): array
    {
        return [
            'id' => $reply->id,
            'from_name' => $reply->from_name,
            'from_address' => $reply->from_address,
            'subject' => $reply->subject,
            'excerpt' => $reply->excerpt(),
            'body' => $reply->body,
            'received_at' => $reply->received_at?->toIso8601String(),

            'classification' => $reply->classification?->value,
            'classification_color' => $reply->classification?->color(),
            'summary' => $reply->summary,
            'signals' => $reply->signals ?? [],
            'needs_attention' => $reply->needsAttention(),
            'reviewed_at' => $reply->reviewed_at?->toIso8601String(),

            'lead_id' => $reply->lead_id,
            'company' => $reply->lead?->company?->name,
            'job_title' => $reply->lead?->job_title,
            'answers_draft' => $reply->draft === null ? null : [
                'id' => $reply->draft->id,
                'subject' => $reply->draft->subject,
            ],
            'tasks' => $reply->followUpTasks
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'status' => $t->status->value,
                    'due_on' => $t->due_on?->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }
}
