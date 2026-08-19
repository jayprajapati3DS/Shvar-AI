<?php

declare(strict_types=1);

namespace App\Services\AI\Recommendation;

/**
 * Caps a model's confidence at what the stored data can actually support.
 *
 * The prompt asks the model to score honestly. Models are bad at this: asked to
 * rate a match on a record containing only "Acme Medical, Germany", a helpful
 * model will still say 0.9 because the product genuinely suits medical device
 * companies. It is scoring the product, not the evidence.
 *
 * So the score is also constrained deterministically, from how much the record
 * actually says (LeadContextBuilder::evidenceStrength()). This cannot make a
 * recommendation better, only more honest - it never raises a score.
 *
 * When a score is lowered, the original is kept in raw_confidence_score and the
 * UI says so, rather than quietly showing a different number than the model gave.
 */
class ConfidenceCalibrator
{
    /**
     * The most confidence a record of a given completeness can justify.
     *
     * Reads as: with almost nothing recorded you cannot claim better than a
     * "possible" match, however obvious the product seems.
     *
     * @var array<string, array{0: float, 1: float}> label => [minEvidence, ceiling]
     */
    private const CEILINGS = [
        'rich' => [0.75, 1.00],   // description + industry + type + what they sell
        'good' => [0.55, 0.90],   // most of the substance present
        'partial' => [0.35, 0.75],   // industry or type, little else
        'thin' => [0.15, 0.60],   // barely more than a name
        'empty' => [0.00, 0.40],   // essentially nothing to reason from
    ];

    /**
     * @return array{score: float, ceiling: float, capped: bool, band: string}
     */
    public function calibrate(float $modelScore, float $evidenceStrength): array
    {
        $score = max(0.0, min(1.0, $modelScore));
        [$band, $ceiling] = $this->ceilingFor($evidenceStrength);

        $calibrated = min($score, $ceiling);

        return [
            'score' => round($calibrated, 3),
            'ceiling' => $ceiling,
            'capped' => $calibrated < $score - 0.0001,
            'band' => $band,
        ];
    }

    /** @return array{0: string, 1: float} */
    private function ceilingFor(float $evidenceStrength): array
    {
        foreach (self::CEILINGS as $band => [$minimum, $ceiling]) {
            if ($evidenceStrength >= $minimum) {
                return [$band, $ceiling];
            }
        }

        return ['empty', 0.40];
    }

    /** Human-readable band for a final score, per the documented scale. */
    public static function describe(?float $score): string
    {
        return match (true) {
            $score === null => 'Not scored',
            $score >= 0.95 => 'Very strong match',
            $score >= 0.80 => 'Strong match',
            $score >= 0.60 => 'Good match',
            $score >= 0.40 => 'Possible match',
            default => 'Very weak match',
        };
    }

    /** Why a score was capped, for the UI to show. */
    public function explainCap(float $modelScore, float $calibrated, float $evidenceStrength): string
    {
        return sprintf(
            'The model scored this %d%%, lowered to %d%% because the stored record is %d%% complete. '
            .'Fill in the missing company details and re-run for a better-supported score.',
            (int) round($modelScore * 100),
            (int) round($calibrated * 100),
            (int) round($evidenceStrength * 100),
        );
    }
}
