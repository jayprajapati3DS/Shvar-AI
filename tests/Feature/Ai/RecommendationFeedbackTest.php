<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadAnalysis;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\AI\Recommendation\RecommendationFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Learning from the products you add that the AI did not suggest.
 *
 * NOT training - the weights are frozen. This reads back corrections already
 * being made and feeds them into the next prompt.
 *
 * The tests that matter are the ones about restraint. A correction engine that
 * over-reads its own signal produces a prompt telling the model to recommend
 * everything, which is worse than no prompt at all.
 */
class RecommendationFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function feedback(): RecommendationFeedback
    {
        // Rebuilt each time: the service caches its sample set per instance.
        return new RecommendationFeedback;
    }

    /** A lead the AI has actually analysed - the precondition for a correction. */
    private function analysedLead(): Lead
    {
        $lead = Lead::factory()->create(['company_id' => Company::factory()]);

        LeadAnalysis::create([
            'lead_id' => $lead->id,
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
            'company_summary' => 'A summary.',
            'business_opportunity' => 'An opportunity.',
        ]);

        return $lead;
    }

    private function match(
        Lead $lead,
        Product $product,
        RecommendationType $type,
        ?RecommendationStatus $status = null,
    ): LeadProductMatch {
        return LeadProductMatch::create([
            'lead_id' => $lead->id,
            'product_id' => $product->id,
            'recommendation_type' => $type,
            'status' => $status ?? RecommendationStatus::Accepted,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Restraint */
    /* ------------------------------------------------------------------ */

    public function test_nothing_is_learned_with_no_history(): void
    {
        $feedback = $this->feedback();

        $this->assertFalse($feedback->hasEnough());
        $this->assertNull($feedback->promptBlock());
    }

    public function test_a_manual_pick_on_an_unanalysed_lead_is_not_a_correction(): void
    {
        // The AI never had a go, so it cannot have got it wrong. That is data
        // entry, not feedback.
        $lead = Lead::factory()->create();
        $product = Product::factory()->create(['name' => 'MySegmenter']);

        $this->match($lead, $product, RecommendationType::Manual);

        $this->assertSame([], $this->feedback()->missed());
    }

    public function test_one_correction_is_not_enough_to_change_the_prompt(): void
    {
        $product = Product::factory()->create(['name' => 'MySegmenter']);
        $this->match($this->analysedLead(), $product, RecommendationType::Manual);

        $this->assertFalse($this->feedback()->hasEnough());
        $this->assertNull($this->feedback()->promptBlock());
    }

    /* ------------------------------------------------------------------ */
    /* Missed products */
    /* ------------------------------------------------------------------ */

    public function test_a_product_you_added_yourself_counts_as_missed(): void
    {
        $product = Product::factory()->create(['name' => 'MySegmenter']);

        foreach (range(1, 3) as $ignored) {
            $this->match($this->analysedLead(), $product, RecommendationType::Manual);
        }

        $missed = $this->feedback()->missed();

        $this->assertSame('MySegmenter', $missed[0]['product']);
        $this->assertSame(3, $missed[0]['count']);
    }

    public function test_a_product_the_ai_also_suggested_for_that_lead_is_not_missed(): void
    {
        $product = Product::factory()->create(['name' => 'MySegmenter']);

        foreach (range(1, 3) as $ignored) {
            $lead = $this->analysedLead();

            // The AI did name it - the manual row just came first.
            $this->match($lead, $product, RecommendationType::Manual);
            $this->match($lead, $product, RecommendationType::AiSecondary);
        }

        $this->assertSame([], $this->feedback()->missed());
    }

    /* ------------------------------------------------------------------ */
    /* Rejected products */
    /* ------------------------------------------------------------------ */

    public function test_a_product_you_usually_reject_is_reported(): void
    {
        $product = Product::factory()->create(['name' => 'Vision Anatomy VR']);

        foreach (range(1, 4) as $ignored) {
            $this->match(
                $this->analysedLead(),
                $product,
                RecommendationType::AiPrimary,
                RecommendationStatus::Rejected,
            );
        }

        $rejected = $this->feedback()->rejected();

        $this->assertSame('Vision Anatomy VR', $rejected[0]['product']);
        $this->assertSame(4, $rejected[0]['count']);
    }

    public function test_a_product_accepted_more_often_than_rejected_is_left_alone(): void
    {
        $product = Product::factory()->create(['name' => '3dsurgical Platform']);

        $this->match(
            $this->analysedLead(),
            $product,
            RecommendationType::AiPrimary,
            RecommendationStatus::Rejected,
        );

        foreach (range(1, 5) as $ignored) {
            $this->match(
                $this->analysedLead(),
                $product,
                RecommendationType::AiPrimary,
                RecommendationStatus::Accepted,
            );
        }

        // One rejection in six is a normal spread, not a fault in the model.
        $this->assertSame([], $this->feedback()->rejected());
    }

    /* ------------------------------------------------------------------ */
    /* The prompt block */
    /* ------------------------------------------------------------------ */

    public function test_the_corrections_reach_the_prompt_framed_as_hints(): void
    {
        $missed = Product::factory()->create(['name' => 'MySegmenter']);

        foreach (range(1, 3) as $ignored) {
            $this->match($this->analysedLead(), $missed, RecommendationType::Manual);
        }

        $block = (string) $this->feedback()->promptBlock();

        $this->assertStringContainsString('WHAT I HAVE CORRECTED BEFORE', $block);
        $this->assertStringContainsString('MySegmenter', $block);

        // The important half. A hint about where the model goes wrong must
        // never become a licence to recommend something the data does not
        // support - that would turn a correction engine into a bias engine.
        $this->assertStringContainsString('NOT as a rule', $block);
        $this->assertStringContainsString('a hint is not evidence', $block);
    }

    public function test_the_settings_page_shows_what_was_learned(): void
    {
        $product = Product::factory()->create(['name' => 'MySegmenter']);

        foreach (range(1, 3) as $ignored) {
            $this->match($this->analysedLead(), $product, RecommendationType::Manual);
        }

        $response = $this->get(route('settings.ai.index'))->assertOk();

        $feedback = $response->viewData('page')['props']['feedback'];

        $this->assertTrue($feedback['active']);
        $this->assertSame(3, $feedback['corrections']);
        $this->assertSame('MySegmenter', $feedback['missed'][0]['product']);
        $this->assertNotNull($feedback['prompt_block']);
    }
}
