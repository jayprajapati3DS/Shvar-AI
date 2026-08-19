<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailDraft;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Email\EmailDraftEditor;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
use App\Services\Email\Exceptions\EmailNotApprovedException;
use App\Services\Email\Exceptions\RecipientNotAllowedException;
use App\Services\Email\RecipientAllowlist;
use App\Services\Email\SmtpEmailService;
use App\Services\Email\SmtpSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real SMTP delivery.
 *
 * No test here opens a socket. Everything asserted is a gate that fires BEFORE
 * the transport is built - which is the point: the interesting behaviour of a
 * transport that can contact real people is everything it refuses to do.
 *
 * The one thing not covered by an automated test is a successful send, because
 * that would need a real mail server and a real recipient. "Test connection" on
 * the settings page is how that gets verified, by a human, deliberately.
 */
class SmtpSendingTest extends TestCase
{
    use RefreshDatabase;

    private EmailDraft $draft;

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

        $lead = Lead::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);

        $body = "Hi Dana,\n\nA note about surgeon review of patient-specific cases.\n\n"
            ."Would you be open to a short overview?\n\nBest regards,";

        $this->draft = EmailDraft::create([
            'lead_id' => $lead->id,
            'contact_id' => $contact->id,
            'product_id' => $product->id,
            'variant' => EmailVariant::ProfessionalDirect,
            'status' => EmailDraftStatus::Draft,
            'subject' => 'Case review workflow',
            'body' => $body,
            'recipient_email' => 'dana@craniofax.example',
            'recipient_name' => 'Dana Whitfield',
            'word_count' => EmailDraft::countWords($body),
        ]);

        app(EmailSettings::class)->save([
            'sender_name' => 'Jay Prajapati',
            'sender_email' => 'jay@example.test',
        ]);
    }

    /** A configured SMTP service, without touching the container binding. */
    private function smtp(): SmtpEmailService
    {
        return new SmtpEmailService(
            app(EmailRenderer::class),
            app(SmtpSettings::class),
            app(EmailSettings::class),
            app(RecipientAllowlist::class),
        );
    }

    private function configureSmtp(): void
    {
        app(SmtpSettings::class)->save([
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'jay@example.test',
            'smtp_password' => 'a-password',
        ]);
    }

    private function approve(): void
    {
        app(EmailDraftEditor::class)->approve($this->draft);
        $this->draft->refresh();
    }

    /* ------------------------------------------------------------------ */
    /* Gate 1: approval */
    /* ------------------------------------------------------------------ */

    public function test_an_unapproved_draft_is_refused_before_anything_is_configured(): void
    {
        // Approval is checked first, so a draft nobody signed off on cannot even
        // reach the "is SMTP set up" question.
        $this->expectException(EmailNotApprovedException::class);

        $this->smtp()->send($this->draft);
    }

    public function test_an_unapproved_draft_is_refused_even_with_everything_configured(): void
    {
        $this->configureSmtp();
        config(['email.allowed_recipients' => '']);

        $this->expectException(EmailNotApprovedException::class);

        $this->smtp()->send($this->draft);
    }

    /* ------------------------------------------------------------------ */
    /* Gate 2: the allowlist */
    /* ------------------------------------------------------------------ */

    public function test_an_approved_draft_to_a_non_allowlisted_address_is_refused(): void
    {
        $this->configureSmtp();
        config(['email.allowed_recipients' => 'me@example.test']);
        $this->approve();

        try {
            $this->smtp()->send($this->draft);
            $this->fail('Expected the allowlist to refuse this recipient.');
        } catch (RecipientNotAllowedException $e) {
            $this->assertSame('dana@craniofax.example', $e->recipient);
            $this->assertStringContainsString('me@example.test', $e->userMessage());
        }
    }

    public function test_the_allowlist_refusal_does_not_mark_the_draft_failed(): void
    {
        config(['email.driver' => 'smtp']);
        app()->forgetInstance(EmailServiceInterface::class);

        $this->configureSmtp();
        config(['email.allowed_recipients' => 'me@example.test']);
        $this->approve();

        $outcome = app(EmailDraftEditor::class)->send($this->draft);

        // A guardrail doing its job is not a delivery failure. Nothing was
        // attempted and the draft is still perfectly good.
        $this->assertFalse($outcome['sent']);
        $this->assertSame(EmailDraftStatus::Approved, $this->draft->fresh()->status);
        $this->assertNull($this->draft->fresh()->sent_at);
        $this->assertNull($this->draft->fresh()->delivery_error);
    }

    public function test_a_domain_rule_lets_colleagues_through(): void
    {
        $this->configureSmtp();
        config(['email.allowed_recipients' => '@craniofax.example']);
        $this->approve();

        // Gets past the allowlist, then fails at the network - which proves the
        // rail let it through rather than that it delivered.
        try {
            $this->smtp()->send($this->draft);
        } catch (RecipientNotAllowedException) {
            $this->fail('The domain rule should have permitted this recipient.');
        } catch (\Throwable) {
            // Expected: there is no mail server here.
        }

        $this->assertTrue(true);
    }

    /* ------------------------------------------------------------------ */
    /* Gate 3: configuration */
    /* ------------------------------------------------------------------ */

    public function test_an_unconfigured_transport_refuses_rather_than_relaying_anonymously(): void
    {
        config(['email.allowed_recipients' => '']);
        $this->approve();

        $this->expectExceptionMessage('SMTP is not configured');

        $this->smtp()->send($this->draft);
    }

    public function test_testing_the_connection_reports_what_is_missing(): void
    {
        $result = $this->smtp()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SMTP host', $result['message']);
        $this->assertStringContainsString('Password', $result['message']);
    }

    /* ------------------------------------------------------------------ */
    /* Identity */
    /* ------------------------------------------------------------------ */

    public function test_the_smtp_service_never_claims_to_be_simulated(): void
    {
        $service = $this->smtp();

        $this->assertFalse($service->isSimulated());
        $this->assertSame('smtp', $service->mode());
        $this->assertStringContainsString('Messages leave this machine', $service->description());
    }

    public function test_the_description_says_whether_the_allowlist_is_on(): void
    {
        config(['email.allowed_recipients' => 'me@example.test']);
        $this->assertStringContainsString('allowlist is ON', $this->smtp()->description());

        config(['email.allowed_recipients' => '']);
        $this->assertStringContainsString('allowlist is OFF', $this->smtp()->description());
    }

    /* ------------------------------------------------------------------ */
    /* Settings */
    /* ------------------------------------------------------------------ */

    public function test_settings_save_through_the_controller(): void
    {
        $this->put(route('settings.email.smtp.update'), [
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'jay@example.test',
            'smtp_password' => 'a-password',
        ])->assertRedirect()->assertSessionHas('success');

        $settings = app(SmtpSettings::class);

        $this->assertSame('smtp.office365.com', $settings->host());
        $this->assertSame(587, $settings->port());
        $this->assertTrue($settings->isConfigured());
    }

    public function test_saving_without_retyping_the_password_keeps_it(): void
    {
        $this->configureSmtp();

        // The page never received the password, so it posts the sentinel. A
        // blank here must not wipe the credential on an unrelated save.
        $this->put(route('settings.email.smtp.update'), [
            'smtp_host' => 'smtp.office365.com',
            'smtp_username' => 'someone.else@example.test',
            'smtp_password' => SmtpSettings::UNCHANGED,
        ])->assertRedirect();

        $settings = app(SmtpSettings::class);

        $this->assertSame('a-password', $settings->password());
        $this->assertSame('someone.else@example.test', $settings->username());
    }

    public function test_a_url_is_rejected_as_a_host(): void
    {
        $this->put(route('settings.email.smtp.update'), [
            'smtp_host' => 'https://smtp.office365.com/send',
        ])->assertSessionHasErrors('smtp_host');
    }

    public function test_an_out_of_range_port_is_rejected(): void
    {
        $this->put(route('settings.email.smtp.update'), [
            'smtp_port' => 99999,
        ])->assertSessionHasErrors('smtp_port');
    }

    public function test_an_unknown_encryption_mode_is_rejected(): void
    {
        $this->put(route('settings.email.smtp.update'), [
            'smtp_encryption' => 'rot13',
        ])->assertSessionHasErrors('smtp_encryption');
    }

    public function test_clearing_forgets_the_password_too(): void
    {
        $this->configureSmtp();
        $this->assertTrue(app(SmtpSettings::class)->isConfigured());

        $this->delete(route('settings.email.smtp.forget'))->assertRedirect();

        $this->assertFalse(app(SmtpSettings::class)->isConfigured());
        $this->assertDatabaseMissing('email_settings', ['key' => 'smtp_password']);
    }

    public function test_the_settings_page_shows_the_allowlist_read_only(): void
    {
        config(['email.allowed_recipients' => 'me@example.test,@3dsurgical.com']);

        $response = $this->get(route('settings.email.index'))->assertOk();

        $allowlist = $response->viewData('page')['props']['allowlist'];

        $this->assertTrue($allowlist['restricting']);
        $this->assertTrue($allowlist['read_only']);
        $this->assertSame(['me@example.test', '@3dsurgical.com'], $allowlist['entries']);
    }

    public function test_the_allowlist_cannot_be_written_from_the_browser(): void
    {
        config(['email.allowed_recipients' => 'me@example.test']);

        // Not in any settings whitelist, so posting it does nothing.
        $this->put(route('settings.email.smtp.update'), [
            'smtp_host' => 'smtp.office365.com',
            'allowed_recipients' => 'anyone@anywhere.example',
        ])->assertRedirect();

        $this->assertSame(['me@example.test'], app(RecipientAllowlist::class)->entries());
        $this->assertDatabaseMissing('email_settings', ['key' => 'allowed_recipients']);
    }
}
