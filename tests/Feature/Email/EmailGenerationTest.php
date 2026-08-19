<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\AiJobStatus;
use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Enums\RecommendationStatus;
use App\Models\AiJob;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\EmailDraft;
use App\Models\EmailGeneration;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\AI\Exceptions\AiTimeoutException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\Exceptions\InvalidAiResponseException;
use App\Services\Email\EmailGenerator;
use App\Services\Email\EmailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Generating outreach emails from an accepted product recommendation.
 *
 * Ollama is faked throughout - the suite must pass with it absent.
 *
 * The tests worth reading are not the happy paths. They are the ones where the
 * model does something plausible and wrong: writes "[First Name]", signs off
 * with an invented job title, claims a capability the product record never
 * mentioned, or hands back a subject line buried in the body.
 */
class EmailGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Lead $lead;

    private LeadProductMatch $recommendation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create([
            'name' => '3dsurgical Platform',
            'category' => 'Surgical Planning Software',
            'short_description' => 'A case management platform for patient-specific surgical planning.',
            'key_features' => "3D case viewer\nSurgeon review and approval\nCase versioning",
            'value_proposition' => 'Brings surgeon review of patient-specific cases into one place.',
            'active' => true,
        ]);

        $company = Company::factory()->create([
            'name' => 'Craniofax Implants',
            'industry' => 'Medical Devices',
            'company_type' => 'PSI Manufacturer',
            'description' => 'Develops patient-specific cranial implants.',
        ]);

        $this->lead = Lead::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'job_title' => 'Head of Design Engineering',
            'email' => 'dana@craniofax.example',
        ]);

        $this->recommendation = LeadProductMatch::create([
            'lead_id' => $this->lead->id,
            'product_id' => $this->product->id,
            'status' => RecommendationStatus::Accepted,
            'confidence_score' => 0.88,
            'reason' => 'They design patient-specific implants and need surgeon sign-off.',
            'sales_angle' => 'Position as a structured review environment for PSI cases.',
            'suggested_use_case' => 'Case management and surgeon approval.',
            'evidence' => ['Develops patient-specific cranial implants.'],
        ]);

        // A signature, so the renderer has something to append and the quality
        // check does not warn about it in every unrelated test.
        app(EmailSettings::class)->save([
            'sender_name' => 'Jay Prajapati',
            'sender_job_title' => 'Senior Manager, Software Business Development',
            'sender_company' => '3D Surgical',
            'sender_email' => 'jay@example.test',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /** Fakes Ollama returning the given email payload. */
    private function fakeAi(array $payload): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/version' => Http::response(['version' => '0.5.7']),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => json_encode($payload),
                'done' => true,
                'prompt_eval_count' => 1200,
                'eval_count' => 400,
            ]),
        ]);
    }

    /** A well-formed, realistic three-variant response. */
    private function goodPayload(): array
    {
        return [
            'variants' => [
                [
                    'style' => 'professional_direct',
                    'subject' => 'Case review workflow for patient-specific implants',
                    'body' => "Hi Dana,\n\nI work with teams designing patient-specific implants. Our "
                        .'3dsurgical Platform brings surgeon review and approval of those cases into one '
                        ."place, with case versioning so nothing gets lost between revisions.\n\n"
                        .'Given Craniofax designs cranial implants, this may line up with how your team '
                        ."handles surgeon sign-off today.\n\nWould you be open to a short overview?\n\n"
                        .'Best regards,',
                ],
                [
                    'style' => 'consultative',
                    'subject' => 'How does Craniofax handle surgeon sign-off?',
                    'body' => "Hi Dana,\n\nMost teams designing patient-specific implants end up "
                        .'coordinating surgeon approval over email and shared folders, which gets hard to '
                        ."track once case volume grows.\n\nThe 3dsurgical Platform gives that review a "
                        ."structured home, with case versioning.\n\nIs that something your team is "
                        ."looking at?\n\nBest regards,",
                ],
                [
                    'style' => 'executive_short',
                    'subject' => 'Patient-specific case review',
                    'body' => "Hi Dana,\n\nWe provide a case management platform for patient-specific "
                        ."surgical planning, including surgeon review and approval.\n\nWorth a brief "
                        ."call?\n\nBest regards,",
                ],
            ],
            'personalization_points' => [
                'Craniofax develops patient-specific cranial implants (company description).',
                'Dana leads design engineering (contact job title).',
            ],
            'claims_used' => [
                'The 3dsurgical Platform provides surgeon review and approval of cases.',
                'The platform includes case versioning.',
            ],
            'missing_information' => ['Current case volume', 'How surgeon approval is handled today'],
        ];
    }

    private function generator(): EmailGenerator
    {
        return app(EmailGenerator::class);
    }

    private function generate(): EmailGeneration
    {
        return $this->generator()->generate($this->lead, $this->recommendation);
    }

    /* ------------------------------------------------------------------ */
    /* 1. Email generation */
    /* ------------------------------------------------------------------ */

    public function test_it_generates_one_draft_per_variant(): void
    {
        $this->fakeAi($this->goodPayload());

        $generation = $this->generate();

        $this->assertCount(3, $generation->drafts);
        $this->assertSame(
            EmailVariant::values(),
            $generation->drafts->map(fn (EmailDraft $d) => $d->variant->value)->all(),
        );
    }

    public function test_every_generated_draft_starts_as_a_draft_and_is_not_sendable(): void
    {
        $this->fakeAi($this->goodPayload());

        foreach ($this->generate()->drafts as $draft) {
            $this->assertSame(EmailDraftStatus::Draft, $draft->status);
            $this->assertFalse($draft->isSendable());
            $this->assertNull($draft->approved_at);
            $this->assertNull($draft->sent_at);
        }
    }

    public function test_the_run_records_the_model_the_settings_and_the_recommendation(): void
    {
        $this->fakeAi($this->goodPayload());

        $generation = $this->generate();

        $this->assertSame('qwen3:8b', $generation->model);
        $this->assertSame('ollama', $generation->provider);
        $this->assertSame('Professional', $generation->tone->value);
        $this->assertSame('Standard', $generation->length->value);
        $this->assertSame($this->recommendation->id, $generation->lead_product_match_id);
        $this->assertSame($this->product->id, $generation->product_id);
    }

    public function test_it_snapshots_the_recipient_so_the_record_survives_the_contact_changing(): void
    {
        $this->fakeAi($this->goodPayload());

        $draft = $this->generate()->drafts->first();

        $this->assertSame('dana@craniofax.example', $draft->recipient_email);
        $this->assertSame('Dana Whitfield', $draft->recipient_name);

        $this->lead->update(['email' => 'someone.else@craniofax.example']);

        $this->assertSame('dana@craniofax.example', $draft->fresh()->recipient_email);
    }

    public function test_the_prompt_carries_the_product_record_and_never_reaches_the_internet(): void
    {
        $this->fakeAi($this->goodPayload());

        $this->generate();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            return str_contains($prompt, '3dsurgical Platform')
                && str_contains($prompt, 'Craniofax Implants')
                && str_contains($prompt, 'Head of Design Engineering')
                && str_contains($prompt, 'Position as a structured review environment');
        });

        // Every outbound request went to the local Ollama endpoint.
        Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1')
            || str_contains($request->url(), 'localhost'));
    }

    public function test_the_prompt_says_who_the_sender_is_and_that_it_is_not_the_recipient(): void
    {
        // Without this the only company name in the whole prompt is the
        // recipient's, and qwen3:4b duly wrote "I am writing from Alpine
        // Maxillofacial Solutions" - to the person who works at Alpine.
        $this->fakeAi($this->goodPayload());

        $this->generate();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            return str_contains($prompt, '=== ME (the person sending this email) ===')
                && str_contains($prompt, 'Jay Prajapati')
                && str_contains($prompt, '3D Surgical')
                && str_contains($prompt, 'Never write as though I work there');
        });
    }

    public function test_a_regulatory_claim_is_allowed_only_when_the_record_states_it(): void
    {
        // Observed on qwen3:4b against the real seed data: the model wrote "FDA
        // 510(k) cleared", which was correct - the product record says exactly
        // that. An absolute ban would have forced it to omit a true, material
        // fact, so the rule carries the same caveat the Phase 3 prompt does.
        $this->fakeAi($this->goodPayload());

        $this->generate();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $system = $request->data()['system'] ?? '';

            return str_contains($system, 'UNLESS that exact fact is written in the supplied product record')
                && str_contains($system, 'do not imply it');
        });
    }

    public function test_an_ungrounded_regulatory_claim_is_flagged(): void
    {
        // The product in setUp() says nothing about clearance, so a claim about
        // it has to come from somewhere other than the record.
        $payload = $this->goodPayload();
        $payload['claims_used'][] = 'The platform is FDA 510(k) cleared and CE marked under MDR.';

        $this->fakeAi($payload);

        $generation = $this->generate();

        $this->assertStringContainsString(
            'does not appear there',
            implode(' ', $generation->warnings ?? []),
        );
    }

    public function test_the_system_prompt_forbids_narrating_the_lead_source(): void
    {
        // lead_source = "Referral" records how the contact reached us. It does
        // not say by whom, so writing "we were referred to you by..." invents a
        // person. Observed on qwen3:4b.
        $this->fakeAi($this->goodPayload());

        $this->generate();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $system = $request->data()['system'] ?? '';

            return str_contains($system, 'Do NOT narrate it')
                && str_contains($system, 'does not license writing');
        });
    }

    /* ------------------------------------------------------------------ */
    /* 2. Structured JSON parsing */
    /* ------------------------------------------------------------------ */

    public function test_it_asks_for_structured_output_with_the_variant_enum(): void
    {
        $this->fakeAi($this->goodPayload());

        $this->generate();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $format = $request->data()['format'] ?? null;

            return is_array($format)
                && ($format['required'] ?? []) === ['variants', 'claims_used', 'personalization_points']
                && ($format['properties']['variants']['items']['properties']['style']['enum'] ?? [])
                    === EmailVariant::values();
        });
    }

    public function test_a_response_that_is_not_json_fails_rather_than_creating_a_draft(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => 'Sure! Here is the email you asked for.',
                'done' => true,
            ]),
        ]);

        $this->expectException(InvalidAiResponseException::class);

        try {
            $this->generate();
        } finally {
            $this->assertDatabaseCount('email_drafts', 0);
            $this->assertDatabaseCount('email_generations', 0);
        }
    }

    public function test_a_response_with_no_usable_variant_creates_nothing(): void
    {
        $this->fakeAi(['variants' => [], 'claims_used' => []]);

        $this->expectException(InvalidAiResponseException::class);

        try {
            $this->generate();
        } finally {
            $this->assertDatabaseCount('email_drafts', 0);
        }
    }

    public function test_an_unrecognised_variant_style_is_dropped_not_stored(): void
    {
        $payload = $this->goodPayload();
        $payload['variants'][] = [
            'style' => 'aggressively_salesy',
            'subject' => 'Buy now',
            'body' => "Hi Dana,\n\nAct fast.\n\nBest regards,",
        ];

        $this->fakeAi($payload);

        $generation = $this->generate();

        $this->assertCount(3, $generation->drafts);
        $this->assertStringContainsString(
            'unrecognised style',
            implode(' ', $generation->warnings ?? []),
        );
    }

    /* ------------------------------------------------------------------ */
    /* 3-5. Missing information, missing recommendation, invalid product */
    /* ------------------------------------------------------------------ */

    public function test_a_lead_with_no_contact_email_is_refused_with_an_explanation(): void
    {
        $this->fakeAi($this->goodPayload());
        $this->lead->update(['email' => null]);

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendation->id,
        ])->assertRedirect();

        $this->assertStringContainsString('no email address', (string) session('error'));
        $this->assertDatabaseCount('email_drafts', 0);
    }

    public function test_a_lead_with_no_name_is_refused(): void
    {
        $this->fakeAi($this->goodPayload());
        $this->lead->update(['first_name' => null, 'last_name' => null]);

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendation->id,
        ])->assertRedirect();

        $this->assertStringContainsString('no name yet', (string) session('error'));
        $this->assertDatabaseCount('email_drafts', 0);
    }

    public function test_an_unaccepted_recommendation_is_refused(): void
    {
        $this->fakeAi($this->goodPayload());
        $this->recommendation->update(['status' => RecommendationStatus::Suggested]);

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendation->id,
        ])->assertRedirect();

        // Section 5: the email follows the direction the human approved.
        $this->assertStringContainsString('Accept that product recommendation first', (string) session('error'));
        $this->assertDatabaseCount('email_drafts', 0);
    }

    public function test_a_recommendation_belonging_to_another_lead_is_refused(): void
    {
        $this->fakeAi($this->goodPayload());

        $otherLead = Lead::factory()->create();
        $foreign = LeadProductMatch::create([
            'lead_id' => $otherLead->id,
            'product_id' => $this->product->id,
            'status' => RecommendationStatus::Accepted,
        ]);

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $foreign->id,
        ])->assertRedirect();

        $this->assertStringContainsString('does not belong to this lead', (string) session('error'));
        $this->assertDatabaseCount('email_drafts', 0);
    }

    public function test_an_inactive_product_is_refused(): void
    {
        $this->fakeAi($this->goodPayload());
        $this->product->update(['active' => false]);

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendation->id,
        ])->assertRedirect();

        $this->assertStringContainsString('inactive', (string) session('error'));
        $this->assertDatabaseCount('email_drafts', 0);
    }

    /* ------------------------------------------------------------------ */
    /* 6-7. AI failure, Ollama unavailable */
    /* ------------------------------------------------------------------ */

    public function test_ollama_being_unreachable_is_reported_not_crashed(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        // Generation happens in a worker now, so the failure is recorded on the
        // job rather than flashed. What must not happen either way is the
        // exception reaching the browser.
        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendation->id,
        ])->assertRedirect();

        $this->assertDatabaseCount('email_drafts', 0);

        $job = AiJob::latest('id')->firstOrFail();

        $this->assertSame(AiJobStatus::Failed, $job->status);
        $this->assertStringContainsString('Ollama', (string) $job->error);
    }

    public function test_an_ai_failure_is_recorded_in_the_local_log(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        try {
            $this->generate();
        } catch (AiUnavailableException|AiTimeoutException) {
            // Expected - the point is what got logged.
        }

        $log = AiRequest::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(AiRequestType::EmailGeneration, $log->request_type);
        $this->assertNotSame(AiRequestStatus::Success, $log->status);
        $this->assertSame($this->lead->id, $log->lead_id);
    }

    public function test_a_successful_generation_is_logged_as_email_generation(): void
    {
        $this->fakeAi($this->goodPayload());

        $generation = $this->generate();

        $log = AiRequest::query()->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame(AiRequestType::EmailGeneration, $log->request_type);
        $this->assertSame(AiRequestStatus::Success, $log->status);
        $this->assertSame($this->lead->id, $log->lead_id);
        $this->assertTrue($log->structured);
        $this->assertSame($log->id, $generation->ai_request_id);
    }

    /* ------------------------------------------------------------------ */
    /* 8. Draft creation */
    /* ------------------------------------------------------------------ */

    public function test_it_records_what_the_model_wrote_separately_from_the_live_text(): void
    {
        $this->fakeAi($this->goodPayload());

        $draft = $this->generate()->drafts->first();

        $this->assertSame($draft->subject, $draft->ai_subject);
        $this->assertSame($draft->body, $draft->ai_body);
        $this->assertFalse($draft->wasEdited());
    }

    public function test_version_one_is_always_the_models_own_output(): void
    {
        $this->fakeAi($this->goodPayload());

        $draft = $this->generate()->drafts->first();
        $version = $draft->versions()->first();

        $this->assertSame(1, $version->version);
        $this->assertSame('ai', $version->source);
        $this->assertSame($draft->ai_body, $version->body);
    }

    public function test_it_stores_the_word_count_and_the_quality_verdict(): void
    {
        $this->fakeAi($this->goodPayload());

        $draft = $this->generate()->drafts->first();

        $this->assertGreaterThan(0, $draft->word_count);
        $this->assertIsArray($draft->quality);
        $this->assertArrayHasKey('checks', $draft->quality);
    }

    /* ------------------------------------------------------------------ */
    /* 15-17. Quality, placeholders, unsupported claims */
    /* ------------------------------------------------------------------ */

    public function test_a_variant_containing_a_placeholder_is_dropped(): void
    {
        $payload = $this->goodPayload();
        $payload['variants'][0]['body'] = "Hi [First Name],\n\nWe work with [Company].\n\nBest regards,";

        $this->fakeAi($payload);

        $generation = $this->generate();

        // The other two survive; the broken one does not become a draft.
        $this->assertCount(2, $generation->drafts);
        $this->assertStringContainsString('placeholder', implode(' ', $generation->warnings ?? []));
    }

    public function test_a_signature_the_model_wrote_itself_is_removed(): void
    {
        $payload = $this->goodPayload();
        $payload['variants'][0]['body'] = "Hi Dana,\n\nA short note about case review.\n\n"
            ."Best regards,\nJ. Smith\nVP of Sales\n+1 555 0100\nacme.example";

        $this->fakeAi($payload);

        $draft = $this->generate()->drafts
            ->firstWhere('variant', EmailVariant::ProfessionalDirect);

        // The invented job title and phone number must not survive - the
        // application appends the real signature instead.
        $this->assertStringNotContainsString('VP of Sales', $draft->body);
        $this->assertStringNotContainsString('555 0100', $draft->body);
        $this->assertStringEndsWith('Best regards,', $draft->body);
    }

    public function test_a_subject_line_inside_the_body_is_removed(): void
    {
        $payload = $this->goodPayload();
        $payload['variants'][0]['body'] = "Subject: Case review workflow\n\nHi Dana,\n\n"
            ."A short note.\n\nBest regards,";

        $this->fakeAi($payload);

        $draft = $this->generate()->drafts
            ->firstWhere('variant', EmailVariant::ProfessionalDirect);

        $this->assertStringStartsWith('Hi Dana,', $draft->body);
    }

    public function test_html_in_the_model_output_is_stripped(): void
    {
        $payload = $this->goodPayload();
        $payload['variants'][0]['body'] = "Hi Dana,\n\n<script>alert('x')</script>"
            ."<b>A short note</b> about case review.\n\nBest regards,";
        $payload['variants'][0]['subject'] = '<b>Case review</b>';

        $this->fakeAi($payload);

        $draft = $this->generate()->drafts
            ->firstWhere('variant', EmailVariant::ProfessionalDirect);

        $this->assertStringNotContainsString('<script>', $draft->body);
        $this->assertStringNotContainsString('<b>', $draft->body);
        $this->assertSame('Case review', $draft->subject);
    }

    public function test_a_claim_the_product_record_does_not_support_is_flagged(): void
    {
        $payload = $this->goodPayload();
        $payload['claims_used'][] = 'The platform is FDA cleared and ISO 13485 certified with '
            .'deployments across forty European hospitals.';

        $this->fakeAi($payload);

        $generation = $this->generate();

        $this->assertStringContainsString(
            'does not appear there',
            implode(' ', $generation->warnings ?? []),
        );
    }

    public function test_reporting_no_claims_at_all_is_flagged_rather_than_passing_silently(): void
    {
        // Observed on qwen3:4b: three good emails, and claims_used omitted
        // entirely. An email full of product statements with an empty audit of
        // them looks identical to a clean result unless it is said out loud.
        $payload = $this->goodPayload();
        $payload['claims_used'] = [];

        $this->fakeAi($payload);

        $generation = $this->generate();

        $this->assertStringContainsString(
            'did not report which product claims',
            implode(' ', $generation->warnings ?? []),
        );
    }

    public function test_a_claim_drawn_from_the_product_record_is_not_flagged(): void
    {
        $this->fakeAi($this->goodPayload());

        $generation = $this->generate();

        // Both claims in goodPayload() paraphrase the product's own features.
        $this->assertStringNotContainsString(
            'does not appear there',
            implode(' ', $generation->warnings ?? []),
        );
    }

    public function test_claiming_to_have_researched_them_is_flagged(): void
    {
        // The subtle one. "I have been reviewing your work with X" may state a
        // perfectly true fact from the record while asserting an activity that
        // never happened - the model read a database row. Observed on qwen3:4b.
        $payload = $this->goodPayload();
        $payload['variants'][0]['body'] = 'Hi Dana,

I have been reviewing your work with '
            .'patient-specific cranial implants.

Would you be open to a short call?

'
            .'Best regards,';

        $this->fakeAi($payload);

        $generation = $this->generate();

        $this->assertStringContainsString(
            'style rules ban',
            implode(' ', $generation->warnings ?? []),
        );
    }

    public function test_a_banned_marketing_phrase_is_flagged_for_review(): void
    {
        $payload = $this->goodPayload();
        $payload['variants'][0]['body'] = "Hi Dana,\n\nI hope this email finds you well. "
            ."We are a leading provider of surgical planning software.\n\nWould you be open to a "
            ."short call?\n\nBest regards,";

        $this->fakeAi($payload);

        $generation = $this->generate();

        $this->assertStringContainsString(
            'style rules ban',
            implode(' ', $generation->warnings ?? []),
        );
    }

    /* ------------------------------------------------------------------ */
    /* 10. Regeneration builds history */
    /* ------------------------------------------------------------------ */

    public function test_regenerating_does_not_touch_the_previous_run(): void
    {
        $this->fakeAi($this->goodPayload());

        $first = $this->generate();
        $firstBodies = $first->drafts->pluck('body')->all();

        $payload = $this->goodPayload();
        $payload['variants'][0]['body'] = "Hi Dana,\n\nA completely rewritten opening.\n\n"
            .'Would you be open to a short call?'."\n\nBest regards,";
        $this->fakeAi($payload);

        $second = $this->generator()->generate($this->lead, $this->recommendation, null, $first);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->id, $second->regenerated_from_id);
        $this->assertSame($firstBodies, $first->fresh()->drafts->pluck('body')->all());
        $this->assertDatabaseCount('email_generations', 2);
        $this->assertDatabaseCount('email_drafts', 6);
    }

    public function test_extra_instructions_reach_the_model_and_are_recorded(): void
    {
        $this->fakeAi($this->goodPayload());

        $generation = $this->generator()->generate(
            $this->lead,
            $this->recommendation,
            'Focus on segmentation. Do not mention the platform.',
        );

        $this->assertSame(
            'Focus on segmentation. Do not mention the platform.',
            $generation->extra_instructions,
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            return str_contains($request->data()['prompt'] ?? '', 'Focus on segmentation.');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Missing information reporting */
    /* ------------------------------------------------------------------ */

    public function test_it_falls_back_to_computed_gaps_when_the_model_reports_none(): void
    {
        $this->lead->company->update(['description' => null, 'industry' => null]);

        $payload = $this->goodPayload();
        $payload['missing_information'] = [];

        $this->fakeAi($payload);

        $generation = $this->generate();

        $this->assertNotEmpty($generation->missing_information);
        $this->assertContains('Company description', $generation->missing_information);
    }
}
