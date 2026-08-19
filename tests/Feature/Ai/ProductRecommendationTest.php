<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\AI\Exceptions\AiTimeoutException;
use App\Services\AI\Exceptions\AiUnavailableException;
use App\Services\AI\Exceptions\InvalidAiResponseException;
use App\Services\AI\Recommendation\AiProductMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The AI product recommendation engine.
 *
 * Ollama is faked throughout - the suite must pass with it absent.
 */
class ProductRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Product $platform;

    private Product $segmenter;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Product::factory()->create([
            'name' => '3dsurgical Platform',
            'key_features' => "3D Viewer\nHip Planning\nKnee Planning\nShoulder Planning",
        ]);

        $this->segmenter = Product::factory()->create(['name' => 'MySegmenter']);

        $company = Company::factory()->create([
            'name' => 'Craniofax Implants',
            'industry' => 'Medical Devices',
            'company_type' => 'PSI Manufacturer',
            'description' => 'Develops patient-specific cranial implants.',
            'products_services' => 'Cranial plates and surgical guides.',
            'specialties' => 'Maxillofacial surgery',
        ]);

        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'job_title' => 'Head of Design Engineering',
            'department' => 'R&D',
        ]);

        $this->lead = Lead::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);
    }

    /** Fakes Ollama returning the given recommendation payload. */
    private function fakeAi(array $payload): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/version' => Http::response(['version' => '0.5.7']),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => json_encode($payload),
                'done' => true,
                'prompt_eval_count' => 900,
                'eval_count' => 300,
            ]),
        ]);
    }

    /** A well-formed, realistic response. */
    private function goodPayload(): array
    {
        return [
            'company_summary' => 'Develops patient-specific cranial implants.',
            'company_type' => 'PSI Manufacturer',
            'business_opportunity' => 'Surgeon review of patient-specific cases is done ad hoc.',
            'recommended_products' => [
                [
                    'product_id' => $this->platform->id,
                    'product_name' => '3dsurgical Platform',
                    'priority' => 'High',
                    'confidence_score' => 0.88,
                    'reason' => 'They design patient-specific implants and need surgeon sign-off.',
                    'evidence' => ['Develops patient-specific cranial implants.'],
                    'sales_angle' => 'Position as a structured review environment for PSI cases.',
                    'suggested_use_case' => 'Case management and surgeon approval.',
                    'module' => null,
                ],
                [
                    'product_id' => $this->segmenter->id,
                    'product_name' => 'MySegmenter',
                    'priority' => 'Medium',
                    'confidence_score' => 0.65,
                    'reason' => 'Implant design starts from segmented anatomy.',
                    'evidence' => ['Cranial plates and surgical guides.'],
                    'sales_angle' => 'Bring segmentation in-house.',
                    'suggested_use_case' => 'Pre-design anatomy preparation.',
                ],
            ],
            'primary_recommendation' => [
                'product_id' => $this->platform->id,
                'product_name' => '3dsurgical Platform',
                'reason' => 'Closest fit to their stated workflow.',
                'sales_angle' => 'Lead with surgeon review.',
            ],
            'products_to_avoid' => [
                ['product_name' => 'Vision Anatomy VR', 'reason' => 'They are not an education institution.'],
            ],
            'missing_information' => ['Current segmentation tooling', 'Case volume'],
            'recommended_next_action' => 'Ask how surgeon approval is handled today.',
        ];
    }

    private function matcher(): AiProductMatcher
    {
        return app(AiProductMatcher::class);
    }

    /* ---------------------------------------------------------------- */
    /* Happy path */
    /* ---------------------------------------------------------------- */

    public function test_it_produces_an_analysis_with_recommendations(): void
    {
        $this->fakeAi($this->goodPayload());

        $analysis = $this->matcher()->analyse($this->lead);

        $this->assertSame($this->lead->id, $analysis->lead_id);
        $this->assertSame('ollama', $analysis->provider);
        $this->assertSame('Develops patient-specific cranial implants.', $analysis->company_summary);
        $this->assertSame('PSI Manufacturer', $analysis->company_type);
        $this->assertSame($this->platform->id, $analysis->primary_product_id);
        $this->assertSame(['Current segmentation tooling', 'Case volume'], $analysis->missing_information);
        $this->assertSame('Ask how surgeon approval is handled today.', $analysis->recommended_next_action);
        $this->assertCount(2, $analysis->recommendations);
    }

    public function test_the_primary_and_secondary_are_typed_correctly(): void
    {
        $this->fakeAi($this->goodPayload());

        $analysis = $this->matcher()->analyse($this->lead);

        $primary = $analysis->recommendations->firstWhere('product_id', $this->platform->id);
        $secondary = $analysis->recommendations->firstWhere('product_id', $this->segmenter->id);

        $this->assertSame(RecommendationType::AiPrimary, $primary->recommendation_type);
        $this->assertSame(RecommendationType::AiSecondary, $secondary->recommendation_type);
        $this->assertSame(RecommendationPriority::High, $primary->priority);
        $this->assertSame(RecommendationPriority::Medium, $secondary->priority);
    }

    public function test_every_recommendation_starts_awaiting_human_review(): void
    {
        $this->fakeAi($this->goodPayload());

        $analysis = $this->matcher()->analyse($this->lead);

        // The single most important guarantee: the AI never accepts its own work.
        foreach ($analysis->recommendations as $recommendation) {
            $this->assertSame(RecommendationStatus::Suggested, $recommendation->status);
            $this->assertNull($recommendation->reviewed_at);
        }
    }

    public function test_analysis_does_not_change_the_lead(): void
    {
        $this->fakeAi($this->goodPayload());

        $before = $this->lead->only(['lead_status', 'priority', 'company_id', 'contact_id']);

        $this->matcher()->analyse($this->lead);

        $this->assertSame($before, $this->lead->fresh()->only(['lead_status', 'priority', 'company_id', 'contact_id']));
    }

    public function test_reasoning_fields_are_stored(): void
    {
        $this->fakeAi($this->goodPayload());

        $analysis = $this->matcher()->analyse($this->lead);
        $primary = $analysis->recommendations->firstWhere('product_id', $this->platform->id);

        $this->assertStringContainsString('surgeon sign-off', $primary->reason);
        $this->assertSame(['Develops patient-specific cranial implants.'], $primary->evidence);
        $this->assertStringContainsString('review environment', $primary->sales_angle);
        $this->assertSame('Case management and surgeon approval.', $primary->suggested_use_case);
    }

    /* ---------------------------------------------------------------- */
    /* Request wiring */
    /* ---------------------------------------------------------------- */

    public function test_it_sends_the_portfolio_and_lead_data_and_nothing_else(): void
    {
        $this->fakeAi($this->goodPayload());

        $this->matcher()->analyse($this->lead);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return false;
            }

            $body = $request->data();
            $prompt = $body['prompt'];

            return str_contains($prompt, 'Craniofax Implants')
                && str_contains($prompt, 'PSI Manufacturer')
                && str_contains($prompt, 'Head of Design Engineering')
                && str_contains($prompt, 'PRODUCT_ID: '.$this->platform->id)
                && str_contains($prompt, '3dsurgical Platform')
                // Structured, and reasoning suppressed - required for JSON.
                && isset($body['format'])
                && ($body['think'] ?? null) === false
                && str_contains($body['system'], 'Never invent facts');
        });
    }

    public function test_empty_fields_are_marked_not_recorded_rather_than_omitted(): void
    {
        // A model shown nothing invents; a model shown "(not recorded)" reports
        // it as missing. This is why blanks are rendered explicitly.
        $this->lead->company->update(['description' => null, 'products_services' => null]);
        $this->fakeAi($this->goodPayload());

        $this->matcher()->analyse($this->lead->fresh());

        Http::assertSent(function ($request) {
            return ! str_contains($request->url(), '/api/generate')
                || str_contains($request->data()['prompt'], '(not recorded)');
        });
    }

    public function test_inactive_products_are_never_offered_to_the_model(): void
    {
        $retired = Product::factory()->inactive()->create(['name' => 'Retired Thing']);
        $this->fakeAi($this->goodPayload());

        $this->matcher()->analyse($this->lead);

        Http::assertSent(function ($request) use ($retired) {
            return ! str_contains($request->url(), '/api/generate')
                || ! str_contains($request->data()['prompt'], $retired->name);
        });
    }

    public function test_private_sales_notes_are_not_sent_to_the_model(): void
    {
        $this->platform->update(['sales_notes' => 'SECRET-INTERNAL-POSITIONING-NOTE']);
        $this->fakeAi($this->goodPayload());

        $this->matcher()->analyse($this->lead);

        Http::assertSent(function ($request) {
            return ! str_contains($request->url(), '/api/generate')
                || ! str_contains($request->data()['prompt'], 'SECRET-INTERNAL-POSITIONING-NOTE');
        });
    }

    /* ---------------------------------------------------------------- */
    /* Logging */
    /* ---------------------------------------------------------------- */

    public function test_the_request_is_logged_against_the_lead(): void
    {
        $this->fakeAi($this->goodPayload());

        $analysis = $this->matcher()->analyse($this->lead);

        $log = AiRequest::sole();

        $this->assertSame(AiRequestType::ProductRecommendation, $log->request_type);
        $this->assertSame(AiRequestStatus::Success, $log->status);
        $this->assertSame($this->lead->id, $log->lead_id);
        $this->assertSame('product_recommendation', $log->template);
        $this->assertTrue($log->structured);

        // The analysis points back at the exact prompt and response.
        $this->assertSame($log->id, $analysis->ai_request_id);
    }

    /* ---------------------------------------------------------------- */
    /* History */
    /* ---------------------------------------------------------------- */

    public function test_re_analysing_adds_history_rather_than_replacing_it(): void
    {
        $this->fakeAi($this->goodPayload());
        $first = $this->matcher()->analyse($this->lead);

        // A second run recommending the SAME product - this is exactly what the
        // old unique(lead_id, product_id) constraint made impossible.
        $second = $this->matcher()->analyse($this->lead);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, LeadAnalysis::where('lead_id', $this->lead->id)->count());
        $this->assertSame(4, LeadProductMatch::where('lead_id', $this->lead->id)->count());

        // The first analysis is untouched.
        $this->assertDatabaseHas('lead_analyses', ['id' => $first->id]);
        $this->assertCount(2, $first->fresh()->recommendations);
    }

    public function test_a_rejected_recommendation_survives_a_re_analysis(): void
    {
        $this->fakeAi($this->goodPayload());

        $first = $this->matcher()->analyse($this->lead);
        $rejected = $first->recommendations->first();
        $rejected->update(['status' => RecommendationStatus::Rejected]);

        $this->matcher()->analyse($this->lead);

        $this->assertSame(RecommendationStatus::Rejected, $rejected->fresh()->status);
    }

    /* ---------------------------------------------------------------- */
    /* Failure modes */
    /* ---------------------------------------------------------------- */

    public function test_ollama_unavailable_raises_and_writes_no_analysis(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        try {
            $this->matcher()->analyse($this->lead);
            $this->fail('Expected AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('Unable to connect to Ollama', $e->userMessage());
        }

        $this->assertDatabaseCount('lead_analyses', 0);
        $this->assertDatabaseCount('lead_product_matches', 0);
        // The failed attempt is still logged, against the lead.
        $this->assertSame($this->lead->id, AiRequest::sole()->lead_id);
    }

    public function test_a_timeout_raises_and_writes_no_analysis(): void
    {
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));

        $this->expectException(AiTimeoutException::class);

        try {
            $this->matcher()->analyse($this->lead);
        } finally {
            $this->assertDatabaseCount('lead_analyses', 0);
        }
    }

    public function test_unparseable_output_raises_and_writes_no_analysis(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => 'I am afraid I cannot help with that.',
            ]),
        ]);

        $this->expectException(InvalidAiResponseException::class);

        try {
            $this->matcher()->analyse($this->lead);
        } finally {
            $this->assertDatabaseCount('lead_analyses', 0);
        }
    }

    public function test_a_company_outside_the_market_yields_no_recommendations(): void
    {
        // Scenario 5: the engine must be willing to recommend nothing.
        $this->fakeAi([
            'company_summary' => 'Builds fleet management software for haulage operators.',
            'company_type' => 'Software Vendor',
            'business_opportunity' => 'No medical activity is recorded. This portfolio does not apply.',
            'recommended_products' => [],
            'primary_recommendation' => null,
            'products_to_avoid' => [],
            'missing_information' => [],
            'recommended_next_action' => 'Consider removing this lead as out of market.',
        ]);

        $analysis = $this->matcher()->analyse($this->lead);

        $this->assertCount(0, $analysis->recommendations);
        $this->assertNull($analysis->primary_product_id);
        $this->assertNull($analysis->primary_confidence);
        $this->assertStringContainsString('does not apply', $analysis->business_opportunity);
    }
}
