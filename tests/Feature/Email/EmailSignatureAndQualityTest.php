<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailLength;
use App\Enums\EmailTone;
use App\Enums\EmailVariant;
use App\Models\Company;
use App\Models\EmailDraft;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Email\EmailQualityChecker;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The signature block and the pre-approval quality checks.
 *
 * The signature is the part of an outreach email most likely to contain a
 * confident falsehood if a model writes it, so the application composes it from
 * configured fields and the model never touches it. These tests pin that down.
 */
class EmailSignatureAndQualityTest extends TestCase
{
    use RefreshDatabase;

    private EmailDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::factory()->create(['name' => '3dsurgical Platform']);
        $company = Company::factory()->create(['name' => 'Craniofax Implants']);

        $lead = Lead::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'email' => 'dana@craniofax.example',
        ]);

        $body = "Hi Dana,\n\nI work with teams designing patient-specific implants. Our platform "
            .'brings surgeon review of those cases into one place, with case versioning so nothing '
            ."is lost between revisions.\n\nWould you be open to a short overview?\n\nBest regards,";

        $this->draft = EmailDraft::create([
            'lead_id' => $lead->id,
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

    private function settings(): EmailSettings
    {
        return app(EmailSettings::class);
    }

    private function renderer(): EmailRenderer
    {
        return app(EmailRenderer::class);
    }

    private function checker(): EmailQualityChecker
    {
        return app(EmailQualityChecker::class);
    }

    /** @return array<string, array{status: string, detail: string|null}> */
    private function checks(?EmailLength $length = null): array
    {
        $result = $this->checker()->check($this->draft->fresh(), $length);

        return collect($result['checks'])->keyBy('key')->all();
    }

    /* ------------------------------------------------------------------ */
    /* 14. Signature handling */
    /* ------------------------------------------------------------------ */

    public function test_the_signature_is_composed_from_the_profile_fields(): void
    {
        $this->settings()->save([
            'sender_name' => 'Jay Prajapati',
            'sender_job_title' => 'Senior Manager, Software Business Development',
            'sender_company' => '3D Surgical',
            'sender_email' => 'jay@example.test',
            'sender_phone' => '+91 99999 00000',
        ]);

        $this->assertSame(
            "Jay Prajapati\nSenior Manager, Software Business Development\n3D Surgical\n"
            ."jay@example.test\n+91 99999 00000",
            app(EmailSettings::class)->signature(),
        );
    }

    public function test_blank_profile_fields_do_not_produce_empty_signature_lines(): void
    {
        $this->settings()->save([
            'sender_name' => 'Jay Prajapati',
            'sender_job_title' => null,
            'sender_company' => '3D Surgical',
        ]);

        $this->assertSame("Jay Prajapati\n3D Surgical", app(EmailSettings::class)->signature());
    }

    public function test_a_hand_written_signature_replaces_the_composed_one(): void
    {
        $this->settings()->save([
            'sender_name' => 'Jay Prajapati',
            'signature' => "Jay\n3D Surgical | Bengaluru",
        ]);

        $settings = app(EmailSettings::class);

        $this->assertSame("Jay\n3D Surgical | Bengaluru", $settings->signature());
        $this->assertFalse($settings->isUsingComposedSignature());
    }

    public function test_resetting_the_signature_restores_the_composed_one(): void
    {
        $this->settings()->save([
            'sender_name' => 'Jay Prajapati',
            'signature' => 'Something custom',
        ]);

        $this->delete(route('settings.email.signature.reset'))->assertRedirect();

        $this->assertSame('Jay Prajapati', app(EmailSettings::class)->signature());
        $this->assertTrue(app(EmailSettings::class)->isUsingComposedSignature());
    }

    public function test_the_signature_is_appended_by_the_application_not_stored_in_the_body(): void
    {
        $this->settings()->save(['sender_name' => 'Jay Prajapati', 'sender_company' => '3D Surgical']);

        $rendered = $this->renderer()->render($this->draft);

        // The stored body ends at the sign-off...
        $this->assertStringEndsWith('Best regards,', $this->draft->body);
        $this->assertStringNotContainsString('Jay Prajapati', $this->draft->body);

        // ...and the rendered email carries the signature after it.
        $this->assertStringEndsWith("Best regards,\n\nJay Prajapati\n3D Surgical", $rendered['body']);
    }

    public function test_an_unconfigured_signature_produces_a_plain_unsigned_email(): void
    {
        $rendered = $this->renderer()->render($this->draft);

        $this->assertSame('', $rendered['signature']);
        $this->assertStringEndsWith('Best regards,', rtrim($rendered['body']));
    }

    public function test_the_preview_carries_nothing_internal(): void
    {
        $this->settings()->save(['sender_name' => 'Jay Prajapati']);
        $this->draft->update([
            'ai_model' => 'qwen3:8b',
            'personalization_points' => ['They design patient-specific implants.'],
        ]);

        $rendered = $this->renderer()->render($this->draft->fresh());

        // Section 33: the recipient must never see model, confidence, reasoning
        // or internal notes.
        $this->assertArrayNotHasKey('ai_model', $rendered);
        $this->assertArrayNotHasKey('personalization_points', $rendered);
        $this->assertStringNotContainsString('qwen3', $rendered['body']);
        $this->assertStringNotContainsString('patient-specific implants.', $rendered['signature']);
    }

    /* ------------------------------------------------------------------ */
    /* 15. Quality checks */
    /* ------------------------------------------------------------------ */

    public function test_a_good_draft_passes_every_essential_check(): void
    {
        $this->settings()->save(['sender_name' => 'Jay Prajapati']);

        $result = $this->checker()->check($this->draft);

        $this->assertFalse($result['blocking']);
        $this->assertFalse($this->checker()->blocks($result));
    }

    public function test_a_missing_recipient_is_a_blocking_failure(): void
    {
        $this->draft->update(['recipient_email' => null]);
        $this->draft->lead->update(['email' => null]);

        $checks = $this->checks();

        $this->assertSame('fail', $checks['recipient']['status']);
        $this->assertTrue($this->checker()->check($this->draft->fresh())['blocking']);
    }

    public function test_a_malformed_recipient_address_is_a_blocking_failure(): void
    {
        $this->draft->update(['recipient_email' => 'not-an-address']);

        $this->assertSame('fail', $this->checks()['recipient']['status']);
    }

    public function test_an_empty_subject_is_a_blocking_failure(): void
    {
        $this->draft->update(['subject' => '']);

        $this->assertSame('fail', $this->checks()['subject']['status']);
    }

    public function test_a_placeholder_is_a_blocking_failure(): void
    {
        $this->draft->update(['body' => "Hi [First Name],\n\nA note.\n\nBest regards,"]);

        $checks = $this->checks();

        $this->assertSame('fail', $checks['placeholders']['status']);
        $this->assertStringContainsString('[First Name]', (string) $checks['placeholders']['detail']);
    }

    public function test_a_missing_greeting_is_only_a_warning(): void
    {
        $this->draft->update(['body' => 'A note about case review. Would you be open to a call?']);

        $checks = $this->checks();

        $this->assertSame('warn', $checks['greeting']['status']);
        $this->assertFalse($this->checker()->check($this->draft->fresh())['blocking']);
    }

    public function test_a_missing_call_to_action_is_only_a_warning(): void
    {
        $this->draft->update(['body' => "Hi Dana,\n\nWe make surgical planning software.\n\nBest regards,"]);

        $checks = $this->checks();

        $this->assertSame('warn', $checks['call_to_action']['status']);
        $this->assertFalse($this->checker()->check($this->draft->fresh())['blocking']);
    }

    public function test_ai_meta_language_is_flagged(): void
    {
        $this->draft->update([
            'body' => "Certainly! Here is the email you asked for.\n\nHi Dana,\n\nA note.\n\nBest regards,",
        ]);

        $this->assertSame('warn', $this->checks()['meta_language']['status']);
    }

    public function test_the_length_check_measures_against_the_configured_setting(): void
    {
        $this->draft->update(['body' => "Hi Dana,\n\nToo short.\n\nBest regards,"]);

        $shortCheck = $this->checks(EmailLength::Detailed);

        $this->assertSame('warn', $shortCheck['length']['status']);
        $this->assertStringContainsString('Detailed', (string) $shortCheck['length']['detail']);

        // The same body against the Short setting is still under the floor, but
        // the message names the setting the user actually chose.
        $this->assertStringContainsString('Short', (string) $this->checks(EmailLength::Short)['length']['detail']);
    }

    public function test_a_slightly_long_email_is_not_warned_about(): void
    {
        // Standard tops out at 180; the checker allows 25% overshoot - 225 words -
        // before complaining, because the band is a target and not a rule.
        $body = "Hi Dana,\n\n".str_repeat('word ', 200)."\n\nWould you be open to a call?\n\nBest regards,";
        $this->draft->update(['body' => $body]);

        $this->assertSame('pass', $this->checks(EmailLength::Standard)['length']['status']);

        // Well past it, though, and it says so.
        $body = "Hi Dana,\n\n".str_repeat('word ', 260)."\n\nWould you be open to a call?\n\nBest regards,";
        $this->draft->update(['body' => $body]);

        $this->assertSame('warn', $this->checks(EmailLength::Standard)['length']['status']);
    }

    public function test_a_missing_signature_is_flagged_but_does_not_block(): void
    {
        $checks = $this->checks();

        $this->assertSame('warn', $checks['signature']['status']);
        $this->assertFalse($this->checker()->check($this->draft->fresh())['blocking']);
    }

    public function test_a_deleted_product_is_flagged_but_does_not_block(): void
    {
        $this->draft->product->delete();

        $checks = $this->checks();

        $this->assertSame('warn', $checks['product']['status']);
        $this->assertFalse($this->checker()->check($this->draft->fresh())['blocking']);
    }

    /* ------------------------------------------------------------------ */
    /* Settings */
    /* ------------------------------------------------------------------ */

    public function test_tone_and_length_default_sensibly(): void
    {
        $settings = app(EmailSettings::class);

        $this->assertSame(EmailTone::Professional, $settings->tone());
        $this->assertSame(EmailLength::Standard, $settings->length());
    }

    public function test_settings_are_saved_through_the_controller(): void
    {
        $this->put(route('settings.email.update'), [
            'sender_name' => 'Jay Prajapati',
            'sender_email' => 'jay@example.test',
            'tone' => 'Consultative',
            'length' => 'Short',
        ])->assertRedirect()->assertSessionHas('success');

        $settings = app(EmailSettings::class);

        $this->assertSame('Jay Prajapati', $settings->senderName());
        $this->assertSame(EmailTone::Consultative, $settings->tone());
        $this->assertSame(EmailLength::Short, $settings->length());
    }

    public function test_a_key_outside_the_whitelist_is_ignored(): void
    {
        $this->settings()->save([
            'sender_name' => 'Jay Prajapati',
            'smtp_password' => 'hunter2',
            'driver' => 'gmail',
        ]);

        $this->assertDatabaseMissing('email_settings', ['key' => 'smtp_password']);
        $this->assertDatabaseMissing('email_settings', ['key' => 'driver']);
        $this->assertDatabaseHas('email_settings', ['key' => 'sender_name']);
    }

    public function test_an_unrecognised_tone_falls_back_to_the_default(): void
    {
        $this->settings()->save(['tone' => 'Aggressive']);

        $this->assertSame(EmailTone::Professional, app(EmailSettings::class)->tone());
    }

    public function test_a_malformed_sender_email_is_rejected(): void
    {
        $this->put(route('settings.email.update'), [
            'sender_email' => 'not-an-address',
        ])->assertSessionHasErrors('sender_email');
    }

    public function test_markup_is_stripped_from_signature_fields(): void
    {
        $this->put(route('settings.email.update'), [
            'sender_name' => '<script>alert(1)</script>Jay Prajapati',
        ])->assertRedirect();

        $this->assertSame('alert(1)Jay Prajapati', app(EmailSettings::class)->senderName());
    }
}
