<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Requests\BulkProductActionRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Resources\LeadResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\BulkEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use RedirectsToOrigin;

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'category', 'active']);

        $products = Product::query()
            ->withCount('leadMatches')
            ->search($filters['search'] ?? null)
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when(
                // 'active' arrives as the string "1"/"0" from the query string;
                // an absent value must not filter at all.
                isset($filters['active']) && $filters['active'] !== '',
                fn ($q) => $q->where('active', (bool) $filters['active'])
            )
            ->orderBy('name')
            ->get();

        return Inertia::render('Products/Index', [
            'products' => ProductResource::collection($products),
            'filters' => $filters,
            'filterOptions' => [
                'categories' => Product::query()
                    ->whereNotNull('category')
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category')
                    ->all(),
            ],
            'bulkFields' => Product::bulkFieldsForUi(),
        ]);
    }

    public function show(Product $product): Response
    {
        $product->loadCount('leadMatches');

        return Inertia::render('Products/Show', [
            'product' => new ProductResource($product),
            // Leads this product is currently attached to.
            'leads' => LeadResource::collection(
                $product->leads()
                    ->with('company:id,name')
                    ->latest('leads.updated_at')
                    ->get()
            ),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create($request->validated());

        return $this->backTo('products.index')
            ->with('success', "Product \"{$product->name}\" created.");
    }

    public function update(StoreProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return $this->backTo('products.index')->with('success', "Product \"{$product->name}\" updated.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        $name = $product->name;

        // Attached lead matches go with it (cascadeOnDelete). Deactivating is
        // usually the better move; the UI warns about this.
        $product->delete();

        return to_route('products.index')
            ->with('success', "Product \"{$name}\" deleted.");
    }

    public function bulkUpdate(BulkProductActionRequest $request, BulkEditor $editor): RedirectResponse
    {
        $changed = $editor->update(
            Product::class,
            $request->ids(),
            $request->values(),
            $request->clear(),
        );

        return $this->backTo('products.index')->with(
            $changed > 0 ? 'success' : 'error',
            $changed > 0
                ? "Updated {$changed} product(s)."
                : 'Nothing to update - no fields were changed.'
        );
    }

    public function bulkDestroy(BulkProductActionRequest $request, BulkEditor $editor): RedirectResponse
    {
        $deleted = $editor->delete(Product::class, $request->ids());

        return $this->backTo('products.index')->with(
            'success',
            "Deleted {$deleted} product(s), along with any lead opportunities using them."
        );
    }
}
