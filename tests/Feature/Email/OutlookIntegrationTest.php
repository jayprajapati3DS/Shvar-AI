<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Models\Activity;
use App\Models\Company;
use App\Models\EmailDraft;
use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Email\EmailDraftEditor;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
use App\Services\Email\Exceptions\EmailNotApprovedException;
use App\Services\Email\Exceptions\RecipientNotAllowedException;
use App\Services\Email\Outlook\FakeOutlookGateway;
use App\Services\Email\Outlook\OutlookException;
use App\Services\Email\Outlook\OutlookGatewayInterface;
use App\Services\Email\Outlook\OutlookMailboxSync;
use App\Services\Email\OutlookEmailService;
use App\Services\Email\RecipientAllowlist;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sending through, and reading from, the Outlook desktop app.
 *
 * Every test here runs against FakeOutlookGateway. Nothing touches COM: that
 * needs Windows, a registered classic Outlook, a MAPI profile and a signed-in
 * user, and a suite requiring all four would not run on a colleague's laptop
 * and would rot quietly.
 *
 * The two things worth asserting hardest:
 *
 *   - A handed-off draft is Queued, not Sent. It is sitting in a window on
 *     someone's screen; calling it sent would put a lie in the timeline.
 *   - Only mail from CRM contacts is ever read. The real mailbox has thousands
 *     of unrelated messages in it.
 */
class OutlookIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FakeOutlookGateway $outlook;

    private EmailDraft $draft;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlook = new FakeOutlookGateway('Jay Prajapati');
        $this->app->instance(OutlookGatewayInterface::class, $this->outlook);

        config(['email.allowed_recipients' => '']);

        $product = Product::factory()->create(['name' => '3dsurgical Platform']);
        $company = Company::factory()->create(['name' => 'Craniofax Implants']);

        $this->lead = Lead::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'email' => 'dana@craniofax.example',
        ]);

        $body = "Hi Dana,\n\nA note about surgeon review.\n\nWould you be open to a call?\n\nBest regards,";

        $this->draft = EmailDraft::create([
            'lead_id' => $this->lead->id,
            'product_id' => $product->id,
            'variant' => EmailVariant::ProfessionalDirect,
            'status' => EmailDraftStatus::Draft,
            'subject' => 'Case review workflow',
            'body' => $body,
            'recipient_email' => 'dana@craniofax.example',
            'recipient_name' => 'Dana Whitfield',
            'word_count' => EmailDraft::countWords($body),
        ]);
    }

    private function service(): OutlookEmailService
    {
        return new OutlookEmailService(
            app(EmailRenderer::class),
            $this->outlook,
            app(RecipientAllowlist::class),
        );
    }

    private function approve(): void
    {
        app(EmailDraftEditor::class)->approve($this->draft);
        $this->draft->refresh();
    }

    private function useOutlookDriver(): void
    {
        config(['email.driver' => 'outlook']);
        $this->app->forgetInstance(EmailServiceInterface::class);
    }

    /* ------------------------------------------------------------------ */
    /* Handing a draft to Outlook */
    /* ------------------------------------------------------------------ */

    public function test_an_unapproved_draft_is_never_handed_to_outlook(): void
    {
        $this->expectException(EmailNotApprovedException::class);

        try {
            $this->service()->send($this->draft);
        } finally {
            $this->assertSame([], $this->outlook->displayed);
        }
    }

    public function test_an_approved_draft_is_opened_in_outlook_with_the_signature(): void
    {
        app(EmailSettings::class)->save(['sender_name' => 'Jay Prajapati']);
        $this->approve();

        $result = $this->service()->send($this->draft);

        $this->assertCount(1, $this->outlook->displayed);

        $message = $this->outlook->displayed[0];

        $this->assertSame('dana@craniofax.example', $message['to']);
        $this->assertSame('Case review workflow', $message['subject']);
        $this->assertStringContainsString('Hi Dana,', $message['body']);
        // The application appends the signature, not the model and not Outlook.
        $this->assertStringContainsString('Jay Prajapati', $message['body']);

        $this->assertTrue($result->handedOff);
        $this->assertFalse($result->delivered);
        $this->assertFalse($result->simulated);
    }

    public function test_a_handed_off_draft_becomes_queued_not_sent(): void
    {
        $this->useOutlookDriver();
        $this->approve();

        $outcome = app(EmailDraftEditor::class)->send($this->draft);

        $this->draft->refresh();

        // It is sitting in a compose window. Nothing has been sent.
        $this->assertTrue($outcome['sent']);
        $this->assertSame(EmailDraftStatus::Queued, $this->draft->status);
        $this->assertNull($this->draft->sent_at);
        $this->assertSame('outlook', $this->draft->delivery_mode);
        $this->assertNotNull($this->draft->delivery_reference);
    }

    public function test_the_timeline_says_it_is_waiting_rather_than_sent(): void
    {
        $this->useOutlookDriver();
        $this->approve();

        app(EmailDraftEditor::class)->send($this->draft);

        $activity = Activity::query()
            ->where('subject_id', $this->lead->id)
            ->latest('id')
            ->first();

        $this->assertSame('Email opened in Outlook', $activity->title);
        $this->assertStringContainsString('press Send in Outlook', $activity->body);
    }

    public function test_the_allowlist_applies_to_outlook_too(): void
    {
        // The message would be sitting in a window addressed to a real person,
        // one keystroke from going out.
        config(['email.allowed_recipients' => 'me@example.test']);
        $this->approve();

        $this->expectException(RecipientNotAllowedException::class);

        try {
            $this->service()->send($this->draft);
        } finally {
            $this->assertSame([], $this->outlook->displayed);
        }
    }

    public function test_an_unreachable_outlook_is_reported_not_crashed(): void
    {
        $this->outlook->unavailable();
        $this->useOutlookDriver();
        $this->approve();

        $outcome = app(EmailDraftEditor::class)->send($this->draft);

        $this->assertFalse($outcome['sent']);
        $this->assertSame(EmailDraftStatus::Failed, $this->draft->fresh()->status);
    }

    public function test_the_outlook_service_never_claims_to_be_simulated(): void
    {
        $service = $this->service();

        $this->assertFalse($service->isSimulated());
        $this->assertSame('outlook', $service->mode());
        $this->assertStringContainsString('You press Send', $service->description());
    }

    /* ------------------------------------------------------------------ */
    /* Reconciling */
    /* ------------------------------------------------------------------ */

    public function test_a_queued_draft_stays_queued_until_it_is_really_sent(): void
    {
        $this->useOutlookDriver();
        $this->approve();
        app(EmailDraftEditor::class)->send($this->draft);

        $promoted = app(OutlookMailboxSync::class)->reconcileQueued();

        // Outlook has not reported it as sent, so it has not been sent.
        $this->assertSame(0, $promoted);
        $this->assertSame(EmailDraftStatus::Queued, $this->draft->fresh()->status);
    }

    public function test_a_queued_draft_becomes_sent_once_outlook_reports_it(): void
    {
        $this->useOutlookDriver();
        $this->approve();
        app(EmailDraftEditor::class)->send($this->draft);

        $this->outlook->markSent((string) $this->draft->fresh()->delivery_reference);

        $promoted = app(OutlookMailboxSync::class)->reconcileQueued();

        $this->assertSame(1, $promoted);
        $this->assertSame(EmailDraftStatus::Sent, $this->draft->fresh()->status);
        $this->assertNotNull($this->draft->fresh()->sent_at);
    }

    /* ------------------------------------------------------------------ */
    /* Reading the inbox */
    /* ------------------------------------------------------------------ */

    public function test_only_mail_from_crm_contacts_is_imported(): void
    {
        $this->outlook
            ->withInboxMessage([
                'from_address' => 'dana@craniofax.example',
                'subject' => 'Re: Case review workflow',
                'body' => 'Happy to see a demo next week.',
            ])
            ->withInboxMessage([
                // Not a contact. The real mailbox is full of these.
                'from_address' => 'newsletter@somewhere.example',
                'subject' => 'Your weekly digest',
                'body' => 'Ten things about medical devices.',
            ])
            ->withInboxMessage([
                'from_address' => 'accounts@unrelated.example',
                'subject' => 'Invoice 4471',
                'body' => 'Please find attached.',
            ]);

        $result = app(OutlookMailboxSync::class)->sync();

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseCount('email_replies', 1);
        $this->assertDatabaseHas('email_replies', ['from_address' => 'dana@craniofax.example']);
        $this->assertDatabaseMissing('email_replies', ['from_address' => 'newsletter@somewhere.example']);
        $this->assertStringContainsString('Nothing else in your mailbox was read', $result['message']);
    }

    public function test_the_sync_is_idempotent(): void
    {
        $this->outlook->withInboxMessage([
            'entry_id' => 'stable-id-1',
            'from_address' => 'dana@craniofax.example',
            'body' => 'A reply.',
        ]);

        app(OutlookMailboxSync::class)->sync();
        $second = app(OutlookMailboxSync::class)->sync();

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertDatabaseCount('email_replies', 1);
    }

    public function test_an_imported_reply_is_attached_to_its_lead(): void
    {
        $this->outlook->withInboxMessage([
            'from_address' => 'dana@craniofax.example',
            'from_name' => 'Dana Whitfield',
            'subject' => 'Re: Case review workflow',
            'body' => 'Interested, send me the overview.',
        ]);

        app(OutlookMailboxSync::class)->sync();

        $reply = EmailReply::first();

        $this->assertSame($this->lead->id, $reply->lead_id);
        $this->assertSame($this->lead->id, $reply->lead_id);
        $this->assertSame('Dana Whitfield', $reply->from_name);
    }

    public function test_a_reply_is_matched_to_the_draft_it_answers(): void
    {
        $this->draft->update([
            'status' => EmailDraftStatus::Sent,
            'sent_at' => now()->subDay(),
        ]);

        $this->outlook->withInboxMessage([
            'from_address' => 'dana@craniofax.example',
            'subject' => 'Re: Case review workflow',
            'body' => 'Sounds good.',
            'received_at' => new DateTimeImmutable('now'),
        ]);

        app(OutlookMailboxSync::class)->sync();

        $this->assertSame($this->draft->id, EmailReply::first()->email_draft_id);
    }

    public function test_a_lead_with_no_address_is_not_looked_for(): void
    {
        $this->lead->update(['email' => null]);

        $result = app(OutlookMailboxSync::class)->sync();

        $this->assertSame(0, $result['leads']);
        $this->assertStringContainsString('nothing to look for', $result['message']);
    }

    public function test_the_address_match_is_case_insensitive(): void
    {
        $this->outlook->withInboxMessage([
            'from_address' => 'DANA@Craniofax.EXAMPLE',
            'body' => 'A reply.',
        ]);

        $this->assertSame(1, app(OutlookMailboxSync::class)->sync()['imported']);
    }

    /* ------------------------------------------------------------------ */
    /* Over HTTP */
    /* ------------------------------------------------------------------ */

    public function test_the_replies_page_renders(): void
    {
        $this->outlook->withInboxMessage([
            'from_address' => 'dana@craniofax.example',
            'subject' => 'Re: Case review',
            'body' => 'A reply.',
        ]);

        app(OutlookMailboxSync::class)->sync();

        $this->get(route('replies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Replies/Index')
                ->has('replies.data', 1)
                ->where('replies.data.0.from_address', 'dana@craniofax.example'));
    }

    public function test_syncing_over_http_reports_what_it_did(): void
    {
        $this->outlook->withInboxMessage([
            'from_address' => 'dana@craniofax.example',
            'body' => 'A reply.',
        ]);

        $this->post(route('replies.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('email_replies', 1);
    }

    public function test_an_outlook_failure_during_sync_is_reported_not_crashed(): void
    {
        $this->outlook->throwing(
            new OutlookException(
                'COM said no',
                'Could not read your Outlook inbox.',
                'Make sure Outlook is running.',
            )
        );

        $this->post(route('replies.sync'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('email_replies', 0);
    }

    public function test_marking_a_reply_reviewed(): void
    {
        $this->outlook->withInboxMessage([
            'from_address' => 'dana@craniofax.example',
            'body' => 'A reply.',
        ]);

        app(OutlookMailboxSync::class)->sync();
        $reply = EmailReply::first();

        $this->assertTrue($reply->needsAttention());

        $this->post(route('replies.review', $reply))->assertRedirect();

        $this->assertNotNull($reply->fresh()->reviewed_at);
        $this->assertFalse($reply->fresh()->needsAttention());
    }

    public function test_deleting_a_reply_only_removes_the_local_copy(): void
    {
        $this->outlook->withInboxMessage([
            'from_address' => 'dana@craniofax.example',
            'body' => 'A reply.',
        ]);

        app(OutlookMailboxSync::class)->sync();

        $this->delete(route('replies.destroy', EmailReply::first()))
            ->assertRedirect();

        $this->assertDatabaseCount('email_replies', 0);
        $this->assertStringContainsString('untouched in Outlook', (string) session('success'));
    }
}
