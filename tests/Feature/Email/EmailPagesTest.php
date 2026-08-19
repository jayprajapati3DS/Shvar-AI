<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Enums\RecommendationStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailDraft;
use App\Models\EmailDraftVersion;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\Email\EmailDraftEditor;
use App\Services\Email\EmailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Phase 4 screens.
 *
 * The assertion that matters most here is a negative one: the preview handed to
 * the browser must carry nothing the recipient should never see - no model
 * name, no confidence score, no reasoning, no internal notes.
 */
class EmailPagesTest extends TestCase
{
    use RefreshDatabase;

    private EmailDraft $draft;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::factory()->create(['name' => '3dsurgical Platform']);
        $company = Company::factory()->create(['name' => 'Craniofax Implants']);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'email' => 'dana@craniofax.example',
        ]);

        $this->lead = Lead::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);

        $body = "Hi Dana,\n\nA note about surgeon review of patient-specific cases.\n\n"
            ."Would you be open to a short overview?\n\nBest regards,";

        $this->draft = EmailDraft::create([
            'lead_id' => $this->lead->id,
            'contact_id' => $contact->id,
            'product_id' => $product->id,
            'variant' => EmailVariant::Consultative,
            'status' => EmailDraftStatus::Draft,
            'subject' => 'Case review workflow',
            'body' => $body,
            'ai_subject' => 'Case review workflow',
            'ai_body' => $body,
            'recipient_email' => 'dana@craniofax.example',
            'recipient_name' => 'Dana Whitfield',
            'ai_model' => 'qwen3:8b',
            'word_count' => EmailDraft::countWords($body),
        ]);

        // Version 1 is always the AI's own output, exactly as EmailGenerator
        // writes it. Without this the history would start at the first edit.
        EmailDraftVersion::create([
            'email_draft_id' => $this->draft->id,
            'version' => 1,
            'subject' => $this->draft->subject,
            'body' => $body,
            'source' => EmailDraftVersion::SOURCE_AI,
            'word_count' => EmailDraft::countWords($body),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The list */
    /* ------------------------------------------------------------------ */

    public function test_the_drafts_list_renders(): void
    {
        $this->get(route('email-drafts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailDrafts/Index')
                ->has('drafts.data', 1)
                ->where('drafts.data.0.subject', 'Case review workflow')
                ->where('drafts.data.0.status_label', 'AI generated')
                ->where('drafts.data.0.lead.company.name', 'Craniofax Implants'));
    }

    public function test_email_drafts_is_no_longer_a_placeholder(): void
    {
        $this->get(route('email-drafts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('EmailDrafts/Index'));
    }

    public function test_the_list_filters_by_status(): void
    {
        app(EmailDraftEditor::class)->approve($this->draft);

        $this->get(route('email-drafts.index', ['status' => 'Draft']))
            ->assertInertia(fn (Assert $page) => $page->has('drafts.data', 0));

        $this->get(route('email-drafts.index', ['status' => 'Approved']))
            ->assertInertia(fn (Assert $page) => $page->has('drafts.data', 1));
    }

    public function test_the_list_filters_by_variant_and_company(): void
    {
        $this->get(route('email-drafts.index', ['variant' => 'executive_short']))
            ->assertInertia(fn (Assert $page) => $page->has('drafts.data', 0));

        $this->get(route('email-drafts.index', ['company_id' => $this->lead->company_id]))
            ->assertInertia(fn (Assert $page) => $page->has('drafts.data', 1));
    }

    public function test_the_list_searches_by_recipient_and_subject(): void
    {
        $this->get(route('email-drafts.index', ['search' => 'Dana']))
            ->assertInertia(fn (Assert $page) => $page->has('drafts.data', 1));

        $this->get(route('email-drafts.index', ['search' => 'nothing matches this']))
            ->assertInertia(fn (Assert $page) => $page->has('drafts.data', 0));
    }

    /* ------------------------------------------------------------------ */
    /* The editor */
    /* ------------------------------------------------------------------ */

    public function test_the_editor_renders_with_the_quality_panel_and_preview(): void
    {
        app(EmailSettings::class)->save(['sender_name' => 'Jay Prajapati']);

        $this->get(route('email-drafts.show', $this->draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('EmailDrafts/Show')
                ->where('draft.data.subject', 'Case review workflow')
                ->has('quality.checks')
                ->where('preview.to', 'dana@craniofax.example')
                ->where('preview.subject', 'Case review workflow')
                ->where('sending.simulated', true)
                ->where('sending.mode', 'simulated'));
    }

    public function test_the_preview_contains_nothing_the_recipient_should_not_see(): void
    {
        app(EmailSettings::class)->save(['sender_name' => 'Jay Prajapati']);

        $response = $this->get(route('email-drafts.show', $this->draft))->assertOk();

        $preview = $response->viewData('page')['props']['preview'];

        // Section 33. Internal detail belongs on the draft, not in the email.
        $this->assertArrayNotHasKey('ai_model', $preview);
        $this->assertArrayNotHasKey('quality', $preview);
        $this->assertArrayNotHasKey('personalization_points', $preview);
        $this->assertStringNotContainsString('qwen3', $preview['body']);
        $this->assertStringNotContainsString('confidence', mb_strtolower($preview['body']));
    }

    public function test_the_editor_exposes_the_version_history(): void
    {
        app(EmailDraftEditor::class)->saveEdit($this->draft, 'Edited', "Hi Dana,\n\nRewritten.\n\nBest regards,");

        $this->get(route('email-drafts.show', $this->draft->fresh()))
            ->assertInertia(fn (Assert $page) => $page
                ->has('draft.data.versions', 2)
                ->where('draft.data.versions.0.source', 'ai')
                ->where('draft.data.versions.1.source', 'user')
                ->where('draft.data.was_edited', true));
    }

    public function test_the_editor_reports_whether_the_draft_may_be_sent(): void
    {
        $this->get(route('email-drafts.show', $this->draft))
            ->assertInertia(fn (Assert $page) => $page
                ->where('draft.data.is_sendable', false)
                ->where('draft.data.is_approvable', true)
                ->where('draft.data.is_editable', true));

        app(EmailDraftEditor::class)->approve($this->draft);

        $this->get(route('email-drafts.show', $this->draft->fresh()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('draft.data.is_sendable', true)
                ->where('draft.data.is_editable', false));
    }

    /* ------------------------------------------------------------------ */
    /* The lead page */
    /* ------------------------------------------------------------------ */

    public function test_the_lead_page_explains_why_generation_is_unavailable(): void
    {
        $this->get(route('leads.show', $this->lead))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $email = $page->toArray()['props']['email'];

                $this->assertFalse($email['canGenerate']);
                $this->assertStringContainsString(
                    'No accepted product recommendation',
                    implode(' ', $email['blockers']),
                );
            });
    }

    public function test_the_lead_page_offers_generation_once_a_recommendation_is_accepted(): void
    {
        LeadProductMatch::create([
            'lead_id' => $this->lead->id,
            'product_id' => $this->draft->product_id,
            'status' => RecommendationStatus::Accepted,
        ]);

        $this->get(route('leads.show', $this->lead))
            ->assertInertia(function (Assert $page) {
                $email = $page->toArray()['props']['email'];

                $this->assertTrue($email['canGenerate']);
                $this->assertSame([], $email['blockers']);
                $this->assertCount(1, $email['acceptedProducts']);
            });
    }

    public function test_a_merely_suggested_recommendation_does_not_unlock_generation(): void
    {
        LeadProductMatch::create([
            'lead_id' => $this->lead->id,
            'product_id' => $this->draft->product_id,
            'status' => RecommendationStatus::Suggested,
        ]);

        $this->get(route('leads.show', $this->lead))
            ->assertInertia(function (Assert $page) {
                $email = $page->toArray()['props']['email'];

                $this->assertFalse($email['canGenerate']);
                $this->assertSame([], $email['acceptedProducts']);
            });
    }

    public function test_the_lead_page_lists_its_drafts(): void
    {
        $this->get(route('leads.show', $this->lead))
            ->assertInertia(function (Assert $page) {
                $email = $page->toArray()['props']['email'];

                $this->assertCount(1, $email['drafts']);
                $this->assertSame('Case review workflow', $email['drafts'][0]['subject']);
            });
    }

    /* ------------------------------------------------------------------ */
    /* Settings */
    /* ------------------------------------------------------------------ */

    public function test_the_email_settings_page_renders(): void
    {
        $this->get(route('settings.email.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Email')
                ->where('settings.tone', 'Professional')
                ->where('settings.length', 'Standard')
                ->where('settings.configured', false)
                ->has('options.tones')
                ->has('options.lengths')
                ->where('sending.simulated', true));
    }

    public function test_the_settings_page_shows_the_signature_in_context(): void
    {
        app(EmailSettings::class)->save([
            'sender_name' => 'Jay Prajapati',
            'sender_company' => '3D Surgical',
        ]);

        $this->get(route('settings.email.index'))
            ->assertInertia(function (Assert $page) {
                $preview = $page->toArray()['props']['preview'];

                $this->assertStringContainsString("Jay Prajapati\n3D Surgical", $preview);
            });
    }

    public function test_the_settings_page_never_offers_a_mail_server_to_configure(): void
    {
        $response = $this->get(route('settings.email.index'))->assertOk();

        $props = $response->viewData('page')['props'];

        // Phase 4 cannot send. There is no host, port or credential to expose,
        // and this asserts none crept in.
        foreach (['host', 'port', 'username', 'password', 'api_key', 'oauth'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                mb_strtolower(json_encode($props['settings'], JSON_THROW_ON_ERROR)),
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Deletion */
    /* ------------------------------------------------------------------ */

    public function test_deleting_a_draft_takes_its_versions_with_it(): void
    {
        app(EmailDraftEditor::class)->saveEdit($this->draft, 'Edited', "Hi Dana,\n\nRewritten.\n\nBest regards,");

        $this->assertDatabaseCount('email_draft_versions', 2);

        $this->delete(route('email-drafts.destroy', $this->draft))->assertRedirect();

        $this->assertDatabaseCount('email_drafts', 0);
        $this->assertDatabaseCount('email_draft_versions', 0);
    }

    public function test_deleting_a_lead_takes_its_drafts_with_it(): void
    {
        $this->lead->delete();

        $this->assertDatabaseCount('email_drafts', 0);
    }
}
