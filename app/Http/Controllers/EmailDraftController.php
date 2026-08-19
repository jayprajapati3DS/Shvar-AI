<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Requests\UpdateEmailDraftRequest;
use App\Http\Resources\EmailDraftResource;
use App\Models\Company;
use App\Models\EmailDraft;
use App\Models\Product;
use App\Services\Email\EmailDraftEditor;
use App\Services\Email\EmailQualityChecker;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Email Drafts module: list, edit, approve, reject, send.
 *
 * The controller owns no rules of its own. Status transitions live on
 * EmailDraftEditor and the sendability question is answered by
 * EmailDraftStatus, so there is exactly one definition of "may this be sent"
 * and this class asks it rather than restating it.
 */
class EmailDraftController extends Controller
{
    use RedirectsToOrigin;

    public function __construct(
        private readonly EmailDraftEditor $editor,
        private readonly EmailQualityChecker $quality,
        private readonly EmailRenderer $renderer,
        private readonly EmailSettings $settings,
        private readonly EmailServiceInterface $mailer,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'variant', 'product_id', 'company_id', 'from', 'to']);

        $drafts = EmailDraft::query()
            ->with([
                'lead:id,company_id,first_name,last_name,email,job_title,lead_status',
                'lead.company:id,name',
                'lead',
                'product:id,name,category',
            ])
            ->search($filters['search'] ?? null)
            ->filter($filters)
            ->latestFirst()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('EmailDrafts/Index', [
            'drafts' => EmailDraftResource::collection($drafts),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => EmailDraftStatus::options(),
                'variants' => EmailVariant::options(),
                'products' => Product::query()
                    ->whereHas('leadMatches')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Product $p) => ['value' => $p->id, 'label' => $p->name])
                    ->all(),
                'companies' => Company::query()
                    ->whereHas('leads')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])
                    ->all(),
            ],
        ]);
    }

    /**
     * The email editor.
     *
     * `preview` is what the recipient would actually see - subject, body and
     * the configured signature, and nothing internal. No confidence score, no
     * reasoning, no model name. Those are on the draft for the user's benefit
     * and stay on this side of the boundary.
     */
    public function show(EmailDraft $draft): Response
    {
        $draft->load([
            'lead.company',
            'lead',
            'product',
            'recommendation',
            'versions',
            'generation.recommendations.product',
        ]);

        // Recomputed rather than read from the stored column: the signature or
        // length setting may have changed since this draft was generated, and a
        // stale verdict about current text is worse than none.
        $quality = $this->quality->check($draft);

        return Inertia::render('EmailDrafts/Show', [
            'draft' => new EmailDraftResource($draft),
            'quality' => $quality,
            'preview' => $this->renderer->render($draft),
            'signature' => $this->settings->signature(),
            'signatureConfigured' => $this->settings->isConfigured(),
            'generation' => $draft->generation === null ? null : [
                'id' => $draft->generation->id,
                'model' => $draft->generation->model,
                'tone' => $draft->generation->tone->value,
                'length' => $draft->generation->length->value,
                'extra_instructions' => $draft->generation->extra_instructions,
                'personalization_points' => $draft->generation->personalization_points ?? [],
                'claims_used' => $draft->generation->claims_used ?? [],
                'missing_information' => $draft->generation->missing_information ?? [],
                'warnings' => $draft->generation->warnings ?? [],
                'created_at' => $draft->generation->created_at?->toIso8601String(),

                // Every product this email pitches, primary first.
                'products' => $draft->generation->recommendations
                    ->map(fn ($m) => [
                        'name' => $m->product?->name,
                        'is_primary' => (bool) $m->pivot->is_primary,
                    ])
                    ->filter(fn (array $p) => $p['name'] !== null)
                    ->values()
                    ->all(),
            ],
            'sending' => [
                'simulated' => $this->mailer->isSimulated(),
                'mode' => $this->mailer->mode(),
                'description' => $this->mailer->description(),
                'allowed' => (bool) config('email.allow_test_send', false),
            ],
        ]);
    }

    /** Save an edited subject and body. */
    public function update(UpdateEmailDraftRequest $request, EmailDraft $draft): RedirectResponse
    {
        if (! $draft->isEditable()) {
            return $this->backTo('email-drafts.index')->with('error', sprintf(
                'A draft with status "%s" can no longer be edited.',
                $draft->status->value,
            ));
        }

        $before = $draft->status;

        $this->editor->saveEdit(
            $draft,
            (string) $request->input('subject'),
            (string) $request->input('body'),
        );

        return $this->backTo('email-drafts.index')->with('success', $draft->refresh()->status !== $before
            ? 'Saved. This draft is now marked as user edited.'
            : 'Saved.');
    }

    /**
     * Approve a draft for sending.
     *
     * The ONLY route to Approved. Blocking quality failures are refused rather
     * than warned about - see EmailDraftEditor::approve().
     */
    public function approve(EmailDraft $draft): RedirectResponse
    {
        $outcome = $this->editor->approve($draft);

        return $outcome['approved']
            ? back()->with('success', 'Approved. This email is now ready to send.')
            : back()->with('error', $outcome['reason']);
    }

    public function reject(EmailDraft $draft): RedirectResponse
    {
        $this->editor->reject($draft);

        return $this->backTo('email-drafts.index')->with('success', 'Rejected. The draft is kept in the history.');
    }

    public function archive(EmailDraft $draft): RedirectResponse
    {
        $this->editor->archive($draft);

        return $this->backTo('email-drafts.index')->with('success', 'Archived.');
    }

    /**
     * Send an approved draft.
     *
     * Phase 4 resolves only LocalTestEmailService, so this simulates: it writes
     * to the local log and marks the draft Sent with delivery_mode 'simulated'.
     * Nothing leaves the machine.
     */
    public function send(EmailDraft $draft): RedirectResponse
    {
        if (! (bool) config('email.allow_test_send', false)) {
            return $this->backTo('email-drafts.index')->with('error', 'Test send is disabled in this environment.');
        }

        $outcome = $this->editor->send($draft);

        if (! $outcome['sent']) {
            return $this->backTo('email-drafts.index')->with('error', $outcome['reason']);
        }

        return $this->backTo('email-drafts.index')->with('success', $outcome['result']?->simulated
            ? 'Simulated email sent successfully. Nothing left this machine - the message was written '
                .'to the local log.'
            : 'Email sent.');
    }

    public function destroy(EmailDraft $draft): RedirectResponse
    {
        $draft->delete();

        return to_route('email-drafts.index')->with('success', 'Draft deleted.');
    }
}
