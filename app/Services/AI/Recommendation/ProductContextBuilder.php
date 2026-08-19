<?php

declare(strict_types=1);

namespace App\Services\AI\Recommendation;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Renders the product catalogue as prompt context.
 *
 * Everything here comes from the products table. No product fact is written
 * into application code - the database stays the single source of truth, so
 * editing a product in the UI changes what the AI is told, immediately.
 *
 * Only ACTIVE products are offered. A retired product should not be
 * recommended, and the cheapest way to guarantee that is never to mention it.
 */
class ProductContextBuilder
{
    /**
     * Products the AI is allowed to recommend, keyed by id.
     *
     * @return Collection<int, Product>
     */
    public function catalogue(): Collection
    {
        return Product::query()->active()->orderBy('name')->get()->keyBy('id');
    }

    /**
     * The portfolio block for the prompt.
     *
     * Deliberately a readable labelled format rather than JSON: small local
     * models follow prose structure more reliably than nested JSON, and this
     * costs fewer tokens than the equivalent escaped object.
     *
     * @param  Collection<int, Product>|null  $products
     */
    public function render(?Collection $products = null): string
    {
        $products ??= $this->catalogue();

        if ($products->isEmpty()) {
            return 'No active products are configured in the portfolio.';
        }

        return $products
            ->map(fn (Product $product) => $this->renderProduct($product))
            ->implode("\n\n".str_repeat('-', 60)."\n\n");
    }

    private function renderProduct(Product $product): string
    {
        $lines = [];

        // The id is what the model must return, so it leads.
        $lines[] = "PRODUCT_ID: {$product->id}";
        $lines[] = "NAME: {$product->name}";

        if (filled($product->category)) {
            $lines[] = "CATEGORY: {$product->category}";
        }

        if (filled($product->short_description)) {
            $lines[] = "DESCRIPTION: {$product->short_description}";
        }

        // detailed_description is deliberately NOT sent. It is the longest field
        // on a product and, across a seven-product portfolio, it dominated the
        // prompt - pushing input past 3,000 tokens and timing the model out on
        // CPU. short_description plus capabilities and targets carry the signal
        // a match is actually made from; the prose adds length, not accuracy.
        $this->appendList($lines, 'CAPABILITIES', Product::toLines($product->key_features));
        $this->appendList($lines, 'TARGET CUSTOMERS', Product::toLines($product->target_customer));
        $this->appendList($lines, 'TARGET SPECIALTIES', Product::toLines($product->target_specialty));

        if (filled($product->value_proposition)) {
            $lines[] = 'VALUE PROPOSITION: '.$this->collapse($product->value_proposition);
        }

        // sales_notes is deliberately NOT sent. It holds private positioning
        // reminders written for a human, and feeding them in tends to make the
        // model parrot them back as if they were findings about the company.

        return implode("\n", $lines);
    }

    /**
     * The capabilities a product legitimately has, used to validate any module
     * the AI names (e.g. "Knee Planning" inside the 3dsurgical Platform).
     *
     * Read from the product's own key_features, so a module cannot be invented
     * and nothing about the module list is hard-coded here.
     *
     * @return list<string>
     */
    public function modulesFor(Product $product): array
    {
        return Product::toLines($product->key_features);
    }

    /**
     * @param  list<string>  $values
     *
     * Capped: one product with a very long capability list should not crowd out
     * the others. The cap is generous enough to keep every real module visible.
     */
    private function appendList(array &$lines, string $label, array $values, int $limit = 12): void
    {
        if ($values === []) {
            return;
        }

        $lines[] = "{$label}:";

        foreach (array_slice($values, 0, $limit) as $value) {
            $lines[] = "  - {$value}";
        }

        if (count($values) > $limit) {
            $lines[] = '  - (+'.(count($values) - $limit).' more)';
        }
    }

    /** Flattens multi-line text so one field cannot dominate the prompt. */
    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
