<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Enums\RecommendationStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailDraft;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\Email\EmailGenerator;
use App\Services\Email\EmailSettings;
use App\Services\Email\EmailStyleProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Learning from the emails you approve.
 *
 * This is NOT training - the model's weights are frozen and nothing here
 * touches them. It reads back the signal the user is already producing (which
 * variant they approve, how long their emails really are, what they delete) and
 * feeds it into the next prompt.
 *
 * The tests that matter are the ones about restraint: learning from too little
 * data, or from the wrong drafts, produces confident nonsense that then shapes
 * every future email.
 */
class EmailStyleLearningTest extends TestCase
{
    use RefreshDatabase;

    private Lead $lead;

    private LeadProductMatch $recommendation;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::factory()->create([
            'name' => '3dsurgical Platform',
            'short_description' => 'A case management platform for surgical planning.',
            'active' => true,
        ]);

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

        $this->recommendation = LeadProductMatch::create([
            'lead_id' => $this->lead->id,
            'product_id' => $product->id,
            'status' => RecommendationStatus::Accepted,
            'reason' => 'They design patient-specific implants.',
        ]);

        app(EmailSettings::class)->save([
            'sender_name' => 'Jay Prajapati',
            'sender_company' => '3D Surgical',
        ]);
    }

    /**
     * A finished draft in the given state.
     *
     * @param  string|null  $aiBody  What the model originally wrote, when it differs.
     */
    private function draft(
        EmailVariant $variant,
        EmailDraftStatus $status,
        string $body,
        ?string $aiBody = null,
    ): EmailDraft {
        return EmailDraft::create([
            'lead_id' => $this->lead->id,
            'contact_id' => $this->lead->contact_id,
            'product_id' => $this->recommendation->product_id,
            'variant' => $variant,
            'status' => $status,
            'subject' => 'A subject',
            'body' => $body,
            'ai_subject' => 'A subject',
            'ai_body' => $aiBody ?? $body,
            'recipient_email' => 'dana@craniofax.example',
            'word_count' => EmailDraft::countWords($body),
        ]);
    }

    private function profile(): EmailStyleProfile
    {
        // Rebuilt each time: the service caches its sample set per instance.
        return new EmailStyleProfile(app(EmailSettings::class));
    }

    /* ------------------------------------------------------------------ */
    /* Restraint */
    /* ------------------------------------------------------------------ */

    public function test_nothing_is_learned_from_an_empty_history(): void
    {
        $profile = $this->profile();

        $this->assertFalse($profile->hasEnough());
        $this->assertNull($profile->promptBlock());
        $this->assertSame(0, $profile->sampleCount());
    }

    public function test_nothing_is_learned_below_the_minimum_sample_size(): void
    {
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'One approved email.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Two approved emails.');

        // Two is not a pattern. Concluding a style preference from it would
        // shape every future email on the strength of a coin flip.
        $this->assertFalse($this->profile()->hasEnough());
        $this->assertNull($this->profile()->promptBlock());
    }

    public function test_only_approved_and_sent_drafts_are_learned_from(): void
    {
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Draft, 'Never reviewed.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::UserEdited, 'Edited, not approved.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Rejected, 'Explicitly rejected.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Archived, 'Put aside.');

        // A rejected draft is not a style preference, and an unreviewed one is
        // just the model's own output - learning from that would be the model
        // teaching itself its own habits.
        $this->assertSame(0, $this->profile()->sampleCount());
    }

    public function test_a_sent_email_counts_as_the_strongest_signal(): void
    {
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Sent, 'Actually sent.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Approved.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Also approved.');

        $this->assertSame(3, $this->profile()->sampleCount());
        $this->assertTrue($this->profile()->hasEnough());
    }

    /* ------------------------------------------------------------------ */
    /* What it concludes */
    /* ------------------------------------------------------------------ */

    public function test_it_learns_the_variant_you_approve_most(): void
    {
        foreach (range(1, 4) as $i) {
            $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, "Consultative {$i}.");
        }

        $this->draft(EmailVariant::ExecutiveShort, EmailDraftStatus::Approved, 'Executive one.');

        $this->assertSame(EmailVariant::Consultative, $this->profile()->preferredVariant());
    }

    public function test_a_dead_heat_is_not_a_preference(): void
    {
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'One.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Two.');
        $this->draft(EmailVariant::ExecutiveShort, EmailDraftStatus::Approved, 'Three.');
        $this->draft(EmailVariant::ExecutiveShort, EmailDraftStatus::Approved, 'Four.');

        // Telling the model to favour a style picked half the time would be
        // inventing a signal rather than reading one.
        $this->assertNull($this->profile()->preferredVariant());
    }

    public function test_it_learns_your_real_word_count_using_the_median(): void
    {
        foreach ([100, 105, 110, 115, 120, 125] as $words) {
            $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, str_repeat('word ', $words));
        }

        // One enormous outlier must not move the target.
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, str_repeat('word ', 900));

        $typical = $this->profile()->typicalWordCount();

        $this->assertGreaterThanOrEqual(100, $typical);
        $this->assertLessThanOrEqual(130, $typical);
    }

    public function test_a_word_count_is_not_asserted_from_too_few_samples(): void
    {
        // A median of three is one short email away from claiming you write
        // 40-word emails - and a stated number reads as an instruction.
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, str_repeat('word ', 30));
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, str_repeat('word ', 40));
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, str_repeat('word ', 200));

        $profile = $this->profile();

        // Enough to learn from at all...
        $this->assertTrue($profile->hasEnough());
        // ...but not enough to state a length.
        $this->assertNull($profile->typicalWordCount());
        $this->assertStringNotContainsString('words', (string) $profile->promptBlock());
    }

    public function test_it_learns_the_sentences_you_consistently_delete(): void
    {
        $banned = 'Our platform is a truly transformative solution for your organisation.';

        foreach (range(1, 3) as $i) {
            $this->draft(
                EmailVariant::Consultative,
                EmailDraftStatus::Approved,
                "Hi Dana,\n\nKept sentence {$i}.\n\nBest regards,",
                "Hi Dana,\n\nKept sentence {$i}.\n\n{$banned}\n\nBest regards,",
            );
        }

        $rejected = $this->profile()->rejectedPhrases();

        $this->assertContains($banned, $rejected);
    }

    public function test_a_one_off_deletion_is_not_treated_as_a_habit(): void
    {
        $this->draft(
            EmailVariant::Consultative,
            EmailDraftStatus::Approved,
            'Kept.',
            "Kept.\n\nA sentence I removed exactly once and never again anywhere.",
        );
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Two.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Three.');

        $this->assertSame([], $this->profile()->rejectedPhrases());
    }

    public function test_it_reports_how_often_you_rewrite_the_model(): void
    {
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Mine.', 'The AI version.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Untouched one.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Untouched two.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Untouched three.');

        $this->assertSame(0.25, $this->profile()->editRate());
    }

    public function test_it_prefers_emails_you_rewrote_as_worked_examples(): void
    {
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Accepted as written one.');
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Accepted as written two.');
        $this->draft(
            EmailVariant::Consultative,
            EmailDraftStatus::Approved,
            'I rewrote this one entirely.',
            'The model wrote something else.',
        );

        $examples = $this->profile()->examples();

        // A draft you rewrote and then approved is a much stronger statement of
        // how you want it to read than one you accepted as-is.
        $this->assertTrue($examples[0]['edited']);
    }

    /* ------------------------------------------------------------------ */
    /* The prompt block */
    /* ------------------------------------------------------------------ */

    public function test_what_it_learned_reaches_the_prompt(): void
    {
        foreach (range(1, 4) as $i) {
            $this->draft(
                EmailVariant::Consultative,
                EmailDraftStatus::Approved,
                "Hi Dana,\n\nApproved email {$i} with enough words to count.\n\nBest regards,",
            );
        }

        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => json_encode([
                    'variants' => [[
                        'style' => 'professional_direct',
                        'subject' => 'A subject',
                        'body' => "Hi Dana,\n\nA note.\n\nWould you be open to a call?\n\nBest regards,",
                    ]],
                    'claims_used' => [],
                    'personalization_points' => [],
                ]),
                'done' => true,
            ]),
        ]);

        app(EmailGenerator::class)->generate($this->lead, $this->recommendation);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            return str_contains($prompt, 'HOW I ACTUALLY WRITE')
                && str_contains($prompt, 'I approve the Consultative style most often')
                && str_contains($prompt, 'never at the expense of the truth rules');
        });
    }

    public function test_the_prompt_says_nothing_learned_when_there_is_no_history(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => json_encode([
                    'variants' => [[
                        'style' => 'professional_direct',
                        'subject' => 'A subject',
                        'body' => "Hi Dana,\n\nA note.\n\nWould you be open to a call?\n\nBest regards,",
                    ]],
                    'claims_used' => [],
                    'personalization_points' => [],
                ]),
                'done' => true,
            ]),
        ]);

        app(EmailGenerator::class)->generate($this->lead, $this->recommendation);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            return str_contains($prompt, '(nothing learned yet)')
                && ! str_contains($prompt, 'HOW I ACTUALLY WRITE');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Control */
    /* ------------------------------------------------------------------ */

    public function test_learning_is_on_by_default(): void
    {
        $this->assertTrue(app(EmailSettings::class)->learningEnabled());
    }

    public function test_learning_can_be_switched_off(): void
    {
        foreach (range(1, 4) as $i) {
            $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, "Approved {$i}.");
        }

        $this->assertTrue($this->profile()->hasEnough());

        $this->put(route('settings.email.update'), ['learning_enabled' => false])
            ->assertRedirect();

        $this->assertFalse(app(EmailSettings::class)->learningEnabled());
        $this->assertFalse($this->profile()->hasEnough());
        $this->assertNull($this->profile()->promptBlock());
    }

    public function test_the_settings_page_shows_exactly_what_was_learned(): void
    {
        foreach (range(1, 4) as $i) {
            $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, "Approved email {$i}.");
        }

        $response = $this->get(route('settings.email.index'))->assertOk();

        $learning = $response->viewData('page')['props']['learning'];

        // A prompt that silently rewrites itself is how a system quietly gets
        // worse in ways nobody can point at. All of it is on the page.
        $this->assertTrue($learning['active']);
        $this->assertSame(4, $learning['samples']);
        $this->assertSame('consultative', $learning['preferred_variant']);
        $this->assertNotNull($learning['prompt_block']);
        $this->assertArrayHasKey('rejected_phrases', $learning);
        $this->assertArrayHasKey('edit_rate', $learning);
    }

    public function test_the_settings_page_says_how_much_more_is_needed(): void
    {
        $this->draft(EmailVariant::Consultative, EmailDraftStatus::Approved, 'Just one.');

        $response = $this->get(route('settings.email.index'))->assertOk();

        $learning = $response->viewData('page')['props']['learning'];

        $this->assertFalse($learning['active']);
        $this->assertSame(1, $learning['samples']);
        $this->assertSame(EmailStyleProfile::MIN_SAMPLES, $learning['min_samples']);
    }
}
