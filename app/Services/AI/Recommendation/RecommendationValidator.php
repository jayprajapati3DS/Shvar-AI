<?php

declare(strict_types=1);

namespace App\Services\AI\Recommendation;

use App\Enums\RecommendationPriority;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Turns raw model output into something safe to store.
 *
 * The schema constrains shape; this constrains meaning. Everything here exists
 * because a model actually does it:
 *
 *   - references a product_id that is not in the catalogue (or invents one)
 *   - names a real product but with the wrong id
 *   - returns confidence as 92, "0.92", or "high"
 *   - names a module the product does not have
 *   - recommends the same product twice
 *   - returns a primary recommendation that is not in its own list
 *
 * Nothing is trusted. Unknown products are dropped, not guessed at. The output
 * is a plain array ready for persistence - this class never writes to the
 * database, so it stays trivially testable.
 */
class RecommendationValidator
{
    public function __construct(
        private readonly ConfidenceCalibrator $calibrator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Decoded model output.
     * @param  Collection<int, Product>  $catalogue  Allowed products, keyed by id.
     * @return array{
     *     company_summary: string|null,
     *     company_type: string|null,
     *     business_opportunity: string|null,
     *     recommendations: list<array<string, mixed>>,
     *     primary_product_id: int|null,
     *     products_to_avoid: list<array{product_name: string, reason: string}>,
     *     missing_information: list<string>,
     *     recommended_next_action: string|null,
     *     rejected: list<string>
     * }
     */
    public function validate(array $payload, Collection $catalogue, float $evidenceStrength): array
    {
        // Things the model got wrong, surfaced rather than silently swallowed.
        $rejected = [];

        $recommendations = $this->validateRecommendations(
            $this->arrayOf($payload, 'recommended_products'),
            $catalogue,
            $evidenceStrength,
            $rejected,
        );

        $primaryId = $this->resolvePrimary($payload, $recommendations, $catalogue, $rejected);

        // Mark the winner so the UI and the stored rows agree on which one it is.
        $recommendations = $this->applyPrimary($recommendations, $primaryId);

        return [
            'company_summary' => $this->text($payload, 'company_summary'),
            'company_type' => $this->text($payload, 'company_type', 200),
            'business_opportunity' => $this->text($payload, 'business_opportunity'),
            'recommendations' => $recommendations,
            'primary_product_id' => $primaryId,
            'products_to_avoid' => $this->validateAvoid($this->arrayOf($payload, 'products_to_avoid')),
            'missing_information' => $this->stringList($this->arrayOf($payload, 'missing_information')),
            'recommended_next_action' => $this->text($payload, 'recommended_next_action'),
            'rejected' => $rejected,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Recommendations */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<mixed>  $items
     * @param  Collection<int, Product>  $catalogue
     * @param  list<string>  $rejected
     * @return list<array<string, mixed>>
     */
    private function validateRecommendations(
        array $items,
        Collection $catalogue,
        float $evidenceStrength,
        array &$rejected,
    ): array {
        $validated = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = $this->resolveProduct($item, $catalogue);

            if ($product === null) {
                $label = $this->describeUnknown($item);
                $rejected[] = "Ignored a recommendation for an unknown product ({$label}).";

                continue;
            }

            // A model that lists the same product twice gets its first mention.
            if (isset($seen[$product->id])) {
                $rejected[] = "Ignored a duplicate recommendation for {$product->name}.";

                continue;
            }

            $seen[$product->id] = true;

            $rawScore = $this->confidence($item['confidence_score'] ?? null);
            $calibration = $this->calibrator->calibrate($rawScore, $evidenceStrength);

            $validated[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'priority' => RecommendationPriority::parse($item['priority'] ?? null),
                'confidence_score' => $calibration['score'],
                'raw_confidence_score' => round($rawScore, 3),
                'confidence_capped' => $calibration['capped'],
                'confidence_note' => $calibration['capped']
                    ? $this->calibrator->explainCap($rawScore, $calibration['score'], $evidenceStrength)
                    : null,
                'reason' => $this->string($item['reason'] ?? null, 4000),
                'evidence' => $this->stringList($item['evidence'] ?? []),
                'sales_angle' => $this->string($item['sales_angle'] ?? null, 2000),
                'suggested_use_case' => $this->string($item['suggested_use_case'] ?? null, 2000),
                'module' => $this->validateModule($item['module'] ?? null, $product, $rejected),
                'is_primary' => false,
            ];
        }

        // Best pitch first, so the UI never has to re-sort.
        usort($validated, function (array $a, array $b): int {
            return [$b['priority']->weight(), $b['confidence_score']]
                <=> [$a['priority']->weight(), $a['confidence_score']];
        });

        return $validated;
    }

    /**
     * Resolve a recommendation to a real product.
     *
     * Tries the id first, then falls back to an exact name match - a model will
     * sometimes name the right product and attach the wrong id. A name that
     * matches nothing is rejected outright rather than fuzzily guessed at.
     *
     * @param  array<string, mixed>  $item
     * @param  Collection<int, Product>  $catalogue
     */
    private function resolveProduct(array $item, Collection $catalogue): ?Product
    {
        $id = $item['product_id'] ?? null;

        if (is_numeric($id) && $catalogue->has((int) $id)) {
            return $catalogue->get((int) $id);
        }

        $name = trim((string) ($item['product_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return $catalogue->first(
            fn (Product $product) => strcasecmp($product->name, $name) === 0
        );
    }

    /**
     * A module must be one of the product's own capabilities.
     *
     * This is what stops "Knee Planning" being recommended for a product that
     * has no such module. The valid list comes from the product row, so nothing
     * about modules is hard-coded.
     *
     * @param  list<string>  $rejected
     */
    private function validateModule(mixed $module, Product $product, array &$rejected): ?string
    {
        $name = trim((string) ($module ?? ''));

        if ($name === '' || strcasecmp($name, 'null') === 0) {
            return null;
        }

        foreach (Product::toLines($product->key_features) as $capability) {
            if (strcasecmp($capability, $name) === 0) {
                return $capability;
            }

            // Tolerate "Knee Planning module" / "the Knee Planning".
            if (stripos($capability, $name) !== false || stripos($name, $capability) !== false) {
                return $capability;
            }
        }

        $rejected[] = "Ignored module \"{$name}\" - {$product->name} does not list it as a capability.";

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Primary */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $recommendations
     * @param  Collection<int, Product>  $catalogue
     * @param  list<string>  $rejected
     */
    private function resolvePrimary(
        array $payload,
        array $recommendations,
        Collection $catalogue,
        array &$rejected,
    ): ?int {
        if ($recommendations === []) {
            return null;
        }

        $primary = $payload['primary_recommendation'] ?? null;

        if (is_array($primary)) {
            $product = $this->resolveProduct($primary, $catalogue);

            if ($product !== null) {
                $inList = collect($recommendations)->firstWhere('product_id', $product->id);

                if ($inList !== null) {
                    return $product->id;
                }

                // Named a primary it did not actually recommend. Fall through to
                // the top of the list rather than storing an unexplained pick.
                $rejected[] = "Primary recommendation ({$product->name}) was not in the recommendation list; used the highest-ranked instead.";
            }
        }

        return $recommendations[0]['product_id'];
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @return list<array<string, mixed>>
     */
    private function applyPrimary(array $recommendations, ?int $primaryId): array
    {
        return array_map(function (array $item) use ($primaryId): array {
            $item['is_primary'] = $primaryId !== null && $item['product_id'] === $primaryId;

            return $item;
        }, $recommendations);
    }

    /* ------------------------------------------------------------------ */
    /* Field coercion */
    /* ------------------------------------------------------------------ */

    /**
     * Coerce a confidence into 0.0-1.0.
     *
     * Accepts 0.92, "0.92" and 92 - models return all three. A percentage is
     * only assumed above 1, so 1 stays 1.0 rather than becoming 0.01.
     */
    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $score = (float) $value;

        if ($score > 1.0) {
            $score = $score / 100;
        }

        return max(0.0, min(1.0, $score));
    }

    /** @param list<mixed> $items */
    private function validateAvoid(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->string($item['product_name'] ?? null, 200);
            $reason = $this->string($item['reason'] ?? null, 2000);

            if ($name === null) {
                continue;
            }

            $result[] = ['product_name' => $name, 'reason' => $reason ?? ''];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function arrayOf(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                // Models sometimes wrap each entry in {"text": "..."}.
                $item = $item['text'] ?? $item['value'] ?? reset($item);
            }

            $string = $this->string($item, 1000);

            if ($string !== null) {
                $result[] = $string;
            }
        }

        return array_values(array_unique($result));
    }

    /** @param array<string, mixed> $payload */
    private function text(array $payload, string $key, int $limit = 8000): ?string
    {
        return $this->string($payload[$key] ?? null, $limit);
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $string = trim((string) ($value ?? ''));

        if ($string === '' || strcasecmp($string, 'null') === 0) {
            return null;
        }

        return mb_substr($string, 0, $limit);
    }

    /** @param array<string, mixed> $item */
    private function describeUnknown(array $item): string
    {
        $name = trim((string) ($item['product_name'] ?? ''));
        $id = $item['product_id'] ?? null;

        return match (true) {
            $name !== '' && $id !== null => "\"{$name}\", id {$id}",
            $name !== '' => "\"{$name}\"",
            $id !== null => "id {$id}",
            default => 'unidentified',
        };
    }
}
