<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\ActivityType;
use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Models\Activity;
use App\Models\Company;
use App\Models\EmailDraft;
use App\Models\EmailDraftVersion;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Email\EmailDraftEditor;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
use App\Services\Email\Exceptions\EmailNotApprovedException;
use App\Services\Email\LocalTestEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Editing, version history, approval and simulated sending.
 *
 * The single most important property in Phase 4 is that an email cannot reach
 * a person without a human approving it. That is asserted here from three
 * angles - the controller, the editor service, and the transport itself - on
 * purpose: the rule is checked in three places precisely so that a gap in one
 * of them is not a gap in the system.
 *
 * No AI is involved in any of this. Nothing here talks to Ollama.
 */
class EmailApprovalTest extends TestCase
{
    use RefreshDatabase;

    private EmailDraft $draft;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::factory()->create(['name' => '3dsurgical Platform']);
        $company = Company::factory()->create(['name' => 'Craniofax Implants']);

        $this->lead = Lead::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'email' => 'dana@craniofax.example',
        ]);

        $body = "Hi Dana,\n\nI work with teams designing patient-specific implants. Our platform "
            ."brings surgeon review of those cases into one place.\n\nWould you be open to a short "
            ."overview?\n\nBest regards,";

        $this->draft = EmailDraft::create([
            'lead_id' => $this->lead->id,
            'product_id' => $product->id,
            'variant' => EmailVariant::ProfessionalDirect,
            'status' => EmailDraftStatus::Draft,
            'subject' => 'Case review workflow for patient-specific implants',
            'body' => $body,
            'ai_subject' => 'Case review workflow for patient-specific implants',
            'ai_body' => $body,
            'recipient_email' => 'dana@craniofax.example',
            'recipient_name' => 'Dana Whitfield',
            'word_count' => EmailDraft::countWords($body),
            'version' => 1,
        ]);

        EmailDraftVersion::create([
            'email_draft_id' => $this->draft->id,
            'version' => 1,
            'subject' => $this->draft->subject,
            'body' => $body,
            'source' => EmailDraftVersion::SOURCE_AI,
            'word_count' => EmailDraft::countWords($body),
        ]);

        app(EmailSettings::class)->save([
            'sender_name' => 'Jay Prajapati',
            'sender_job_title' => 'Senior Manager, Software Business Development',
            'sender_email' => 'jay@example.test',
        ]);
    }

    private function editor(): EmailDraftEditor
    {
        return app(EmailDraftEditor::class);
    }

    /* ------------------------------------------------------------------ */
    /* 9. Draft editing */
    /* ------------------------------------------------------------------ */

    public function test_editing_moves_the_draft_to_user_edited(): void
    {
        $this->put(route('email-drafts.update', $this->draft), [
            'subject' => 'A subject I wrote myself',
            'body' => "Hi Dana,\n\nMy own wording entirely.\n\nWould you be open to a call?\n\nBest regards,",
        ])->assertRedirect();

        $this->draft->refresh();

        $this->assertSame(EmailDraftStatus::UserEdited, $this->draft->status);
        $this->assertSame('A subject I wrote myself', $this->draft->subject);
        $this->assertTrue($this->draft->wasEdited());
    }

    public function test_editing_never_overwrites_what_the_model_wrote(): void
    {
        $original = $this->draft->ai_body;

        $this->editor()->saveEdit($this->draft, 'New subject', "Hi Dana,\n\nRewritten.\n\nBest regards,");

        $this->draft->refresh();

        $this->assertSame($original, $this->draft->ai_body);
        $this->assertNotSame($original, $this->draft->body);
    }

    public function test_saving_without_changing_anything_does_not_relabel_the_draft(): void
    {
        $this->editor()->saveEdit($this->draft, $this->draft->subject, $this->draft->body);

        $this->assertSame(EmailDraftStatus::Draft, $this->draft->fresh()->status);
        $this->assertSame(1, $this->draft->fresh()->version);
    }

    /* ------------------------------------------------------------------ */
    /* 10. Version history */
    /* ------------------------------------------------------------------ */

    public function test_each_edit_appends_a_version(): void
    {
        $this->editor()->saveEdit($this->draft, 'Second', "Hi Dana,\n\nTake two.\n\nBest regards,");
        $this->editor()->saveEdit($this->draft->refresh(), 'Third', "Hi Dana,\n\nTake three.\n\nBest regards,");

        $versions = $this->draft->fresh()->versions;

        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], $versions->pluck('version')->all());
        $this->assertSame(['ai', 'user', 'user'], $versions->pluck('source')->all());
        $this->assertSame(3, $this->draft->fresh()->version);
    }

    public function test_the_first_version_is_still_readable_after_several_rewrites(): void
    {
        $original = $this->draft->body;

        $this->editor()->saveEdit($this->draft, 'A', "Hi Dana,\n\nOne.\n\nBest regards,");
        $this->editor()->saveEdit($this->draft->refresh(), 'B', "Hi Dana,\n\nTwo.\n\nBest regards,");

        $first = $this->draft->fresh()->versions()->where('version', 1)->first();

        $this->assertSame($original, $first->body);
        $this->assertTrue($first->isAi());
    }

    /* ------------------------------------------------------------------ */
    /* 11. Approval */
    /* ------------------------------------------------------------------ */

    public function test_approving_sets_the_status_and_the_timestamp(): void
    {
        $this->post(route('email-drafts.approve', $this->draft))->assertRedirect();

        $this->draft->refresh();

        $this->assertSame(EmailDraftStatus::Approved, $this->draft->status);
        $this->assertNotNull($this->draft->approved_at);
        $this->assertTrue($this->draft->isSendable());
    }

    public function test_approving_is_recorded_on_the_lead_timeline(): void
    {
        $this->post(route('email-drafts.approve', $this->draft))->assertRedirect();

        $activity = Activity::query()
            ->where('subject_type', Lead::class)
            ->where('subject_id', $this->lead->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('Approved outreach email', $activity->title);
    }

    public function test_approving_twice_does_not_log_a_second_timeline_entry(): void
    {
        $this->post(route('email-drafts.approve', $this->draft))->assertRedirect();
        $before = Activity::where('subject_id', $this->lead->id)->count();

        $this->post(route('email-drafts.approve', $this->draft))->assertRedirect();

        $this->assertSame($before, Activity::where('subject_id', $this->lead->id)->count());
    }

    public function test_a_draft_with_a_placeholder_cannot_be_approved(): void
    {
        $this->draft->update(['body' => "Hi [First Name],\n\nA note.\n\nBest regards,"]);

        $this->post(route('email-drafts.approve', $this->draft))->assertRedirect();

        $this->assertSame(EmailDraftStatus::Draft, $this->draft->fresh()->status);
        $this->assertNull($this->draft->fresh()->approved_at);
        $this->assertStringContainsString('[First Name]', (string) session('error'));
    }

    public function test_a_draft_with_no_recipient_cannot_be_approved(): void
    {
        $this->draft->update(['recipient_email' => null]);
        $this->lead->update(['email' => null]);

        $this->post(route('email-drafts.approve', $this->draft))->assertRedirect();

        $this->assertSame(EmailDraftStatus::Draft, $this->draft->fresh()->status);
    }

    public function test_a_minor_issue_does_not_block_approval(): void
    {
        // No greeting and a short body: both warnings, neither fatal.
        $this->draft->update(['body' => 'A very short note. Would you be open to a call?']);

        $this->post(route('email-drafts.approve', $this->draft))->assertRedirect();

        $this->assertSame(EmailDraftStatus::Approved, $this->draft->fresh()->status);
    }

    public function test_editing_an_approved_draft_revokes_the_approval(): void
    {
        $this->editor()->approve($this->draft);
        $this->assertNotNull($this->draft->fresh()->approved_at);

        // Approving text and then changing it must not leave the changed text
        // approved - that would be sending something nobody signed off on.
        $this->editor()->saveEdit(
            $this->draft->fresh(),
            'Changed after approval',
            "Hi Dana,\n\nDifferent words.\n\nBest regards,",
        );

        $this->draft->refresh();

        $this->assertSame(EmailDraftStatus::UserEdited, $this->draft->status);
        $this->assertNull($this->draft->approved_at);
        $this->assertFalse($this->draft->isSendable());
    }

    public function test_an_approved_draft_can_no_longer_be_edited_through_the_controller(): void
    {
        $this->editor()->approve($this->draft);

        $this->put(route('email-drafts.update', $this->draft->fresh()), [
            'subject' => 'Sneaking a change in',
            'body' => "Hi Dana,\n\nChanged.\n\nBest regards,",
        ])->assertRedirect();

        $this->assertStringContainsString('can no longer be edited', (string) session('error'));
        $this->assertSame('Case review workflow for patient-specific implants', $this->draft->fresh()->subject);
    }

    /* ------------------------------------------------------------------ */
    /* 12. Rejection */
    /* ------------------------------------------------------------------ */

    public function test_rejecting_keeps_the_draft_in_the_history(): void
    {
        $this->post(route('email-drafts.reject', $this->draft))->assertRedirect();

        $this->assertSame(EmailDraftStatus::Rejected, $this->draft->fresh()->status);
        $this->assertDatabaseHas('email_drafts', ['id' => $this->draft->id]);
    }

    public function test_a_rejected_draft_cannot_be_approved(): void
    {
        $this->editor()->reject($this->draft);

        $outcome = $this->editor()->approve($this->draft->fresh());

        $this->assertFalse($outcome['approved']);
        $this->assertSame(EmailDraftStatus::Rejected, $this->draft->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /* 13. Simulated sending */
    /* ------------------------------------------------------------------ */

    public function test_an_unapproved_draft_cannot_be_sent(): void
    {
        $this->post(route('email-drafts.send', $this->draft))->assertRedirect();

        $this->draft->refresh();

        $this->assertStringContainsString('not been approved', (string) session('error'));
        $this->assertSame(EmailDraftStatus::Draft, $this->draft->status);
        $this->assertNull($this->draft->sent_at);
    }

    public function test_a_user_edited_draft_cannot_be_sent_without_approval(): void
    {
        $this->editor()->saveEdit($this->draft, 'Edited', "Hi Dana,\n\nEdited.\n\nBest regards,");

        $this->post(route('email-drafts.send', $this->draft->fresh()))->assertRedirect();

        $this->assertSame(EmailDraftStatus::UserEdited, $this->draft->fresh()->status);
        $this->assertNull($this->draft->fresh()->sent_at);
    }

    public function test_the_transport_itself_refuses_an_unapproved_draft(): void
    {
        // The last line of defence. Even called directly, bypassing the
        // controller and the editor, the service will not send.
        $this->expectException(EmailNotApprovedException::class);

        app(EmailServiceInterface::class)->send($this->draft);
    }

    public function test_an_approved_draft_sends_and_is_marked_simulated(): void
    {
        $this->editor()->approve($this->draft);

        $this->post(route('email-drafts.send', $this->draft->fresh()))->assertRedirect();

        $this->draft->refresh();

        $this->assertSame(EmailDraftStatus::Sent, $this->draft->status);
        $this->assertNotNull($this->draft->sent_at);
        $this->assertSame('simulated', $this->draft->delivery_mode);
        $this->assertStringContainsString('Simulated', (string) session('success'));
    }

    public function test_sending_writes_the_message_to_the_local_log_and_nowhere_else(): void
    {
        Log::spy();

        $this->editor()->approve($this->draft);
        $this->editor()->send($this->draft->fresh());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Simulated email send'
                    && $context['recipient'] === 'dana@craniofax.example'
                    && $context['delivery_mode'] === 'simulated'
                    && str_contains($context['body'], 'Hi Dana,');
            })
            ->once();
    }

    public function test_the_only_bound_email_service_is_the_local_simulated_one(): void
    {
        $service = app(EmailServiceInterface::class);

        $this->assertInstanceOf(LocalTestEmailService::class, $service);
        $this->assertTrue($service->isSimulated());
        $this->assertSame('simulated', $service->mode());
    }

    public function test_a_sent_draft_is_recorded_on_the_lead_timeline(): void
    {
        $this->editor()->approve($this->draft);
        $this->editor()->send($this->draft->fresh());

        $activity = Activity::query()
            ->where('subject_id', $this->lead->id)
            ->where('type', ActivityType::Email->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('Simulated', $activity->title);
        $this->assertStringContainsString('nothing left this machine', $activity->body);
    }

    public function test_test_send_can_be_switched_off_by_configuration(): void
    {
        config(['email.allow_test_send' => false]);

        $this->editor()->approve($this->draft);

        $this->post(route('email-drafts.send', $this->draft->fresh()))->assertRedirect();

        $this->assertStringContainsString('disabled', (string) session('error'));
        $this->assertSame(EmailDraftStatus::Approved, $this->draft->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /* Archiving */
    /* ------------------------------------------------------------------ */

    public function test_archiving_moves_a_draft_out_of_the_way_without_deleting_it(): void
    {
        $this->post(route('email-drafts.archive', $this->draft))->assertRedirect();

        $this->assertSame(EmailDraftStatus::Archived, $this->draft->fresh()->status);
        $this->assertDatabaseHas('email_drafts', ['id' => $this->draft->id]);
    }
}
