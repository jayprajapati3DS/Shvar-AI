<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\LeadProductMatch;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Phase 3 workflow over HTTP: analyse, review, accept, reject, history.
 *
 * The recurring assertion is that nothing happens to the lead without the user
 * asking for it.
 */
class LeadAnalysisPagesTest extends TestCase
{
    use RefreshDatabase;

    private Product $platform;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Product::factory()->create(['name' => '3dsurgical Platform']);

        $company = Company::factory()->create([
            'name' => 'Craniofax Implants',
            'company_type' => 'PSI Manufacturer',
            'description' => 'Develops patient-specific cranial implants.',
        ]);

        $this->lead = Lead::factory()->create([
            'company_id' => $company->id,
            'contact_id' => Contact::factory()->create(['company_id' => $company->id])->id,
        ]);
    }

    private function fakeAi(?array $payload = null): void
    {
        $payload ??= [
            'company_summary' => 'Develops patient-specific cranial implants.',
            'company_type' => 'PSI Manufacturer',
            'business_opportunity' => 'Surgeon review is unstructured.',
            'recommended_products' => [[
                'product_id' => $this->platform->id,
                'product_name' => '3dsurgical Platform',
                'priority' => 'High',
                'confidence_score' => 0.85,
                'reason' => 'They design patient-specific implants.',
                'evidence' => ['Develops patient-specific cranial implants.'],
                'sales_angle' => 'Lead with surgeon review.',
            ]],
            'missing_information' => ['Case volume'],
            'recommended_next_action' => 'Ask how surgeon approval works today.',
        ];

        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/version' => Http::response(['version' => '0.5.7']),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => json_encode($payload),
                'done' => true,
            ]),
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Lead page */
    /* ---------------------------------------------------------------- */

    public function test_the_lead_page_renders_with_no_analysis(): void
    {
        $this->fakeAi();

        $this->get(route('leads.show', $this->lead))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leads/Show')
                ->where('latestAnalysis', null)
                ->has('analyses.data', 0)
                ->where('aiStatus.ready', true));
    }

    public function test_the_lead_page_renders_when_ollama_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        // The CRM must stay usable with AI unavailable.
        $this->get(route('leads.show', $this->lead))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('aiStatus.ready', false));
    }

    /* ---------------------------------------------------------------- */
    /* Analyse */
    /* ---------------------------------------------------------------- */

    public function test_analysing_creates_an_analysis_and_reports_success(): void
    {
        $this->fakeAi();

        $this->post(route('leads.analyze', $this->lead))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Analysis complete'));

        $this->assertDatabaseCount('lead_analyses', 1);
        $this->assertDatabaseCount('lead_product_matches', 1);
    }

    public function test_the_analysis_appears_on_the_lead_page(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $this->get(route('leads.show', $this->lead))
            ->assertInertia(fn (Assert $page) => $page
                // A single JsonResource wraps in `data` (the existing
                // `lead: { data: ... }` convention); a NESTED collection does
                // not, and serialises as a plain array.
                ->where('latestAnalysis.data.company_type', 'PSI Manufacturer')
                ->where('latestAnalysis.data.primary_product_name', '3dsurgical Platform')
                ->where('latestAnalysis.data.primary_confidence_percent', 85)
                ->where('latestAnalysis.data.primary_confidence_band', 'Strong match')
                ->has('latestAnalysis.data.recommendations', 1)
                ->has('analyses.data', 1));
    }

    public function test_an_empty_result_is_reported_as_a_valid_outcome(): void
    {
        $this->fakeAi([
            'company_summary' => 'Fleet software vendor.',
            'business_opportunity' => 'No medical activity recorded.',
            'recommended_products' => [],
        ]);

        $this->post(route('leads.analyze', $this->lead))
            ->assertRedirect()
            // Not an error: the model correctly declining to force a match.
            ->assertSessionHas('info', fn (string $m) => str_contains($m, 'no product'));

        $this->assertDatabaseCount('lead_analyses', 1);
        $this->assertDatabaseCount('lead_product_matches', 0);
    }

    public function test_a_failed_analysis_shows_a_friendly_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->post(route('leads.analyze', $this->lead))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Unable to connect to Ollama'));

        $this->assertDatabaseCount('lead_analyses', 0);

        $error = session('error');
        $this->assertStringNotContainsString('Exception', $error);
        $this->assertStringNotContainsString(base_path(), $error);
    }

    public function test_analysing_never_changes_the_lead_status_or_priority(): void
    {
        $this->fakeAi();

        $before = $this->lead->only(['lead_status', 'priority']);

        $this->post(route('leads.analyze', $this->lead));

        $this->assertSame($before, $this->lead->fresh()->only(['lead_status', 'priority']));
    }

    /* ---------------------------------------------------------------- */
    /* Human review */
    /* ---------------------------------------------------------------- */

    public function test_accepting_a_recommendation_marks_it_and_logs_an_activity(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $match = LeadProductMatch::sole();
        $this->assertSame(RecommendationStatus::Suggested, $match->status);

        $this->post(route('leads.recommendations.accept', [$this->lead, $match]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $match->refresh();

        $this->assertSame(RecommendationStatus::Accepted, $match->status);
        $this->assertNotNull($match->reviewed_at);

        // The timeline records that a human made the call.
        $this->assertDatabaseHas('activities', [
            'subject_type' => Lead::class,
            'subject_id' => $this->lead->id,
            'title' => 'Accepted AI product recommendation',
        ]);
    }

    public function test_accepting_twice_does_not_log_a_duplicate_activity(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $match = LeadProductMatch::sole();

        $this->post(route('leads.recommendations.accept', [$this->lead, $match]));
        // A retry, or a click whose response the browser never saw.
        $this->post(route('leads.recommendations.accept', [$this->lead, $match]))
            ->assertSessionHas('info');

        $this->assertSame(RecommendationStatus::Accepted, $match->fresh()->status);
        $this->assertSame(
            1,
            Activity::where('title', 'Accepted AI product recommendation')->count(),
        );
    }

    public function test_rejecting_keeps_the_row_for_history(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $match = LeadProductMatch::sole();

        $this->post(route('leads.recommendations.reject', [$this->lead, $match]))
            ->assertRedirect();

        $this->assertSame(RecommendationStatus::Rejected, $match->fresh()->status);
        // Rejected, not deleted.
        $this->assertDatabaseCount('lead_product_matches', 1);
    }

    public function test_a_recommendation_belonging_to_another_lead_cannot_be_accepted(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $other = Lead::factory()->create();
        $match = LeadProductMatch::sole();

        $this->post(route('leads.recommendations.accept', [$other, $match]))->assertNotFound();

        $this->assertSame(RecommendationStatus::Suggested, $match->fresh()->status);
    }

    /* ---------------------------------------------------------------- */
    /* History */
    /* ---------------------------------------------------------------- */

    public function test_analysis_history_accumulates_and_is_listed_newest_first(): void
    {
        $this->fakeAi();

        $this->post(route('leads.analyze', $this->lead));
        $this->post(route('leads.analyze', $this->lead));

        $this->assertDatabaseCount('lead_analyses', 2);

        $newest = LeadAnalysis::orderByDesc('id')->first();

        $this->get(route('leads.show', $this->lead))
            ->assertInertia(fn (Assert $page) => $page
                ->has('analyses.data', 2)
                ->where('analyses.data.0.id', $newest->id));
    }

    public function test_a_past_analysis_can_be_fetched_in_full(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $analysis = LeadAnalysis::sole();

        $this->getJson(route('leads.analyses.show', [$this->lead, $analysis]))
            ->assertOk()
            ->assertJsonPath('analysis.company_type', 'PSI Manufacturer')
            ->assertJsonPath('analysis.recommendations.0.product.name', '3dsurgical Platform')
            ->assertJsonPath('analysis.recommendations.0.source', 'AI');
    }

    public function test_an_analysis_from_another_lead_is_not_accessible(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $other = Lead::factory()->create();

        $this->getJson(route('leads.analyses.show', [$other, LeadAnalysis::sole()]))
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------- */
    /* Manual override (§17) */
    /* ---------------------------------------------------------------- */

    public function test_a_product_can_still_be_added_manually(): void
    {
        $this->fakeAi();

        $this->post(route('leads.products.store', $this->lead), [
            'product_id' => $this->platform->id,
            'reason' => 'I know this account.',
        ])->assertRedirect();

        $match = LeadProductMatch::sole();

        $this->assertSame(RecommendationType::Manual, $match->recommendation_type);
        // A deliberate pick needs no review queue.
        $this->assertSame(RecommendationStatus::Accepted, $match->status);
        $this->assertNull($match->confidence_score);
    }

    public function test_a_manual_pick_is_unaffected_by_a_later_analysis(): void
    {
        $this->fakeAi();

        $this->post(route('leads.products.store', $this->lead), ['product_id' => $this->platform->id]);
        $manual = LeadProductMatch::sole();

        $this->post(route('leads.analyze', $this->lead));

        // The AI's row is added alongside; the manual one is untouched.
        $this->assertSame(RecommendationType::Manual, $manual->fresh()->recommendation_type);
        $this->assertSame(RecommendationStatus::Accepted, $manual->fresh()->status);
        $this->assertSame(2, LeadProductMatch::where('lead_id', $this->lead->id)->count());
    }

    public function test_a_product_already_active_cannot_be_added_twice(): void
    {
        $this->post(route('leads.products.store', $this->lead), ['product_id' => $this->platform->id]);

        $this->post(route('leads.products.store', $this->lead), ['product_id' => $this->platform->id])
            ->assertSessionHasErrors('product_id');
    }

    public function test_a_rejected_suggestion_does_not_block_adding_the_product_manually(): void
    {
        $this->fakeAi();
        $this->post(route('leads.analyze', $this->lead));

        $match = LeadProductMatch::sole();
        $this->post(route('leads.recommendations.reject', [$this->lead, $match]));

        // Rejecting the AI's suggestion must not stop you choosing it yourself.
        $this->post(route('leads.products.store', $this->lead), ['product_id' => $this->platform->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, LeadProductMatch::where('lead_id', $this->lead->id)->count());
    }
}
