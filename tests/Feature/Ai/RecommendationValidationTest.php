<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\RecommendationPriority;
use App\Models\Product;
use App\Services\AI\Recommendation\ConfidenceCalibrator;
use App\Services\AI\Recommendation\RecommendationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The layer that refuses to trust model output.
 *
 * Every case here is something a model actually does. The schema constrains
 * shape; this constrains meaning.
 */
class RecommendationValidationTest extends TestCase
{
    use RefreshDatabase;

    private Product $platform;

    private Product $segmenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = Product::factory()->create([
            'name' => '3dsurgical Platform',
            'key_features' => "3D Viewer\nHip Planning\nKnee Planning\nFibula Flap Planning",
        ]);

        $this->segmenter = Product::factory()->create(['name' => 'MySegmenter']);
    }

    /** @return Collection<int, Product> */
    private function catalogue(): Collection
    {
        return Product::all()->keyBy('id');
    }

    private function validator(): RecommendationValidator
    {
        return app(RecommendationValidator::class);
    }

    /** Validate with a rich record, so the calibrator does not cap scores. */
    private function validate(array $payload, float $evidence = 1.0): array
    {
        return $this->validator()->validate($payload, $this->catalogue(), $evidence);
    }

    private function recommendation(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->platform->id,
            'product_name' => '3dsurgical Platform',
            'priority' => 'High',
            'confidence_score' => 0.8,
            'reason' => 'A reason.',
        ], $overrides);
    }

    /* ---------------------------------------------------------------- */
    /* Product identity                                                */
    /* ---------------------------------------------------------------- */

    public function test_an_invented_product_id_is_dropped(): void
    {
        $result = $this->validate([
            'recommended_products' => [
                $this->recommendation(['product_id' => 9999, 'product_name' => 'Imaginary Suite']),
            ],
        ]);

        $this->assertSame([], $result['recommendations']);
        $this->assertStringContainsString('unknown product', $result['rejected'][0]);
        $this->assertStringContainsString('Imaginary Suite', $result['rejected'][0]);
    }

    public function test_a_real_name_with_a_wrong_id_is_recovered_by_name(): void
    {
        // Models routinely name the right product and attach the wrong id.
        $result = $this->validate([
            'recommended_products' => [
                $this->recommendation(['product_id' => 4242, 'product_name' => 'MySegmenter']),
            ],
        ]);

        $this->assertCount(1, $result['recommendations']);
        $this->assertSame($this->segmenter->id, $result['recommendations'][0]['product_id']);
    }

    public function test_a_name_matching_nothing_is_rejected_not_guessed(): void
    {
        $result = $this->validate([
            'recommended_products' => [
                $this->recommendation(['product_id' => null, 'product_name' => 'Segmenter Pro Max']),
            ],
        ]);

        $this->assertSame([], $result['recommendations']);
    }

    public function test_a_duplicate_recommendation_keeps_only_the_first(): void
    {
        $result = $this->validate([
            'recommended_products' => [
                $this->recommendation(['confidence_score' => 0.9]),
                $this->recommendation(['confidence_score' => 0.5]),
            ],
        ]);

        $this->assertCount(1, $result['recommendations']);
        $this->assertSame(0.9, $result['recommendations'][0]['confidence_score']);
        $this->assertStringContainsString('duplicate', $result['rejected'][0]);
    }

    /* ---------------------------------------------------------------- */
    /* Modules                                                         */
    /* ---------------------------------------------------------------- */

    public function test_a_real_module_is_kept(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation(['module' => 'Knee Planning'])],
        ]);

        $this->assertSame('Knee Planning', $result['recommendations'][0]['module']);
    }

    public function test_a_module_the_product_does_not_have_is_rejected(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation(['module' => 'Spine Planning'])],
        ]);

        $this->assertNull($result['recommendations'][0]['module']);
        $this->assertStringContainsString('Spine Planning', $result['rejected'][0]);
        $this->assertStringContainsString('does not list it', $result['rejected'][0]);
    }

    public function test_a_loosely_worded_module_still_matches(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation(['module' => 'the Hip Planning module'])],
        ]);

        // Normalised back to the product's own wording.
        $this->assertSame('Hip Planning', $result['recommendations'][0]['module']);
    }

    /* ---------------------------------------------------------------- */
    /* Confidence coercion                                             */
    /* ---------------------------------------------------------------- */

    public function test_a_percentage_confidence_is_normalised(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation(['confidence_score' => 92])],
        ]);

        $this->assertSame(0.92, $result['recommendations'][0]['confidence_score']);
    }

    public function test_a_string_confidence_is_accepted(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation(['confidence_score' => '0.77'])],
        ]);

        $this->assertSame(0.77, $result['recommendations'][0]['confidence_score']);
    }

    public function test_a_nonsense_confidence_becomes_zero(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation(['confidence_score' => 'very high'])],
        ]);

        $this->assertSame(0.0, $result['recommendations'][0]['confidence_score']);
    }

    public function test_confidence_is_clamped_to_the_valid_range(): void
    {
        $over = $this->validate([
            'recommended_products' => [$this->recommendation(['confidence_score' => 250])],
        ]);
        $under = $this->validate([
            'recommended_products' => [$this->recommendation(['confidence_score' => -3])],
        ]);

        $this->assertSame(1.0, $over['recommendations'][0]['confidence_score']);
        $this->assertSame(0.0, $under['recommendations'][0]['confidence_score']);
    }

    public function test_priority_is_parsed_tolerantly_and_defaults_sensibly(): void
    {
        $result = $this->validate([
            'recommended_products' => [
                $this->recommendation(['priority' => 'high']),
                $this->recommendation(['product_id' => $this->segmenter->id, 'priority' => 'nonsense']),
            ],
        ]);

        $byId = collect($result['recommendations'])->keyBy('product_id');

        $this->assertSame(RecommendationPriority::High, $byId[$this->platform->id]['priority']);
        $this->assertSame(RecommendationPriority::Medium, $byId[$this->segmenter->id]['priority']);
    }

    /* ---------------------------------------------------------------- */
    /* Ordering and primary                                            */
    /* ---------------------------------------------------------------- */

    public function test_recommendations_are_ordered_by_priority_then_confidence(): void
    {
        $result = $this->validate([
            'recommended_products' => [
                $this->recommendation(['product_id' => $this->segmenter->id, 'priority' => 'Medium', 'confidence_score' => 0.95]),
                $this->recommendation(['priority' => 'High', 'confidence_score' => 0.6]),
            ],
        ]);

        // High beats a higher-confidence Medium.
        $this->assertSame($this->platform->id, $result['recommendations'][0]['product_id']);
    }

    public function test_a_primary_that_was_not_recommended_falls_back_to_the_top_pick(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation()],
            'primary_recommendation' => [
                'product_id' => $this->segmenter->id,
                'product_name' => 'MySegmenter',
            ],
        ]);

        $this->assertSame($this->platform->id, $result['primary_product_id']);
        $this->assertStringContainsString('was not in the recommendation list', $result['rejected'][0]);
    }

    public function test_a_missing_primary_defaults_to_the_top_pick(): void
    {
        $result = $this->validate([
            'recommended_products' => [$this->recommendation()],
        ]);

        $this->assertSame($this->platform->id, $result['primary_product_id']);
        $this->assertTrue($result['recommendations'][0]['is_primary']);
    }

    public function test_no_recommendations_means_no_primary(): void
    {
        $result = $this->validate(['recommended_products' => []]);

        $this->assertNull($result['primary_product_id']);
    }

    /* ---------------------------------------------------------------- */
    /* Field coercion                                                  */
    /* ---------------------------------------------------------------- */

    public function test_evidence_entries_wrapped_in_objects_are_unwrapped(): void
    {
        $result = $this->validate([
            'recommended_products' => [
                $this->recommendation(['evidence' => [['text' => 'They design implants.'], 'Plain string']]),
            ],
        ]);

        $this->assertSame(['They design implants.', 'Plain string'], $result['recommendations'][0]['evidence']);
    }

    public function test_the_literal_string_null_is_treated_as_empty(): void
    {
        $result = $this->validate([
            'company_summary' => 'null',
            'recommended_products' => [$this->recommendation(['sales_angle' => 'null'])],
        ]);

        $this->assertNull($result['company_summary']);
        $this->assertNull($result['recommendations'][0]['sales_angle']);
    }

    public function test_a_completely_empty_payload_does_not_explode(): void
    {
        $result = $this->validate([]);

        $this->assertSame([], $result['recommendations']);
        $this->assertNull($result['company_summary']);
        $this->assertNull($result['primary_product_id']);
        $this->assertSame([], $result['missing_information']);
    }

    /* ---------------------------------------------------------------- */
    /* Confidence calibration                                          */
    /* ---------------------------------------------------------------- */

    public function test_a_sparse_record_caps_an_overconfident_score(): void
    {
        // The core guard: a record with almost nothing in it cannot support 0.97
        // however confident the model sounds.
        $result = $this->validate(
            ['recommended_products' => [$this->recommendation(['confidence_score' => 0.97])]],
            evidence: 0.1,
        );

        $recommendation = $result['recommendations'][0];

        $this->assertSame(0.97, $recommendation['raw_confidence_score']);
        $this->assertLessThan(0.97, $recommendation['confidence_score']);
        $this->assertTrue($recommendation['confidence_capped']);
        $this->assertStringContainsString('lowered', $recommendation['confidence_note']);
    }

    public function test_a_rich_record_leaves_a_high_score_alone(): void
    {
        $result = $this->validate(
            ['recommended_products' => [$this->recommendation(['confidence_score' => 0.93])]],
            evidence: 1.0,
        );

        $this->assertSame(0.93, $result['recommendations'][0]['confidence_score']);
        $this->assertFalse($result['recommendations'][0]['confidence_capped']);
    }

    public function test_calibration_never_raises_a_score(): void
    {
        $calibrator = app(ConfidenceCalibrator::class);

        foreach ([0.0, 0.2, 0.5, 0.8, 1.0] as $score) {
            foreach ([0.0, 0.3, 0.6, 1.0] as $evidence) {
                $this->assertLessThanOrEqual(
                    $score,
                    $calibrator->calibrate($score, $evidence)['score'],
                    "Calibration raised {$score} at evidence {$evidence}.",
                );
            }
        }
    }

    public function test_the_documented_confidence_bands_are_reported(): void
    {
        $this->assertSame('Very strong match', ConfidenceCalibrator::describe(0.96));
        $this->assertSame('Strong match', ConfidenceCalibrator::describe(0.85));
        $this->assertSame('Good match', ConfidenceCalibrator::describe(0.70));
        $this->assertSame('Possible match', ConfidenceCalibrator::describe(0.45));
        $this->assertSame('Very weak match', ConfidenceCalibrator::describe(0.10));
        $this->assertSame('Not scored', ConfidenceCalibrator::describe(null));
    }
}
