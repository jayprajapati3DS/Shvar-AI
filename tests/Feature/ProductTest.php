<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_loads_the_full_portfolio(): void
    {
        $this->seed(ProductSeeder::class);

        $this->assertSame(7, Product::count());

        foreach ([
            '3dsurgical Platform',
            'MySegmenter',
            'Medical Segmentation Services',
            'Pre-operative Planning & 3D Design Services',
            'Vision Anatomy VR',
            'Vision Anatomy Table',
            'Custom Medical Software Development',
        ] as $name) {
            $this->assertDatabaseHas('products', ['name' => $name, 'active' => true]);
        }
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductSeeder::class);

        $this->assertSame(7, Product::count());
    }

    public function test_index_lists_products_with_split_list_fields(): void
    {
        Product::factory()->create([
            'name' => 'Test Product',
            'target_customer' => "Hospitals\nImplant companies",
            'key_features' => "One\nTwo\nThree",
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Index')
                ->has('products.data', 1)
                ->has('products.data.0.target_customer_list', 2)
                ->has('products.data.0.key_features_list', 3)
                // The raw text is still sent, for the edit form.
                ->where('products.data.0.target_customer', "Hospitals\nImplant companies"));
    }

    public function test_index_filters_by_active_state(): void
    {
        Product::factory()->create(['name' => 'Live One']);
        Product::factory()->inactive()->create(['name' => 'Retired One']);

        $this->get(route('products.index', ['active' => '1']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Live One'));

        $this->get(route('products.index', ['active' => '0']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Retired One'));

        // No filter must not hide anything.
        $this->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 2));
    }

    public function test_a_product_can_be_created_and_updated(): void
    {
        $this->post(route('products.store'), [
            'name' => 'New Service',
            'category' => 'Professional Services',
            'short_description' => 'Does a thing.',
            'active' => true,
        ])->assertRedirect();

        $product = Product::whereName('New Service')->sole();

        $this->put(route('products.update', $product), [
            'name' => 'Renamed Service',
            'active' => false,
        ])->assertRedirect();

        $product->refresh();

        $this->assertSame('Renamed Service', $product->name);
        $this->assertFalse($product->active);
    }

    public function test_product_name_must_be_unique(): void
    {
        Product::factory()->create(['name' => 'Taken']);

        $this->post(route('products.store'), ['name' => 'Taken', 'active' => true])
            ->assertSessionHasErrors('name');
    }

    public function test_a_product_can_be_renamed_to_its_own_name(): void
    {
        $product = Product::factory()->create(['name' => 'Same Name']);

        // The unique rule must ignore the record being updated.
        $this->put(route('products.update', $product), ['name' => 'Same Name', 'active' => true])
            ->assertSessionHasNoErrors();
    }

    public function test_show_lists_the_leads_a_product_is_attached_to(): void
    {
        $product = Product::factory()->create();
        $lead = Lead::factory()->create();
        $lead->productMatches()->create(['product_id' => $product->id]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Show')
                ->where('product.data.leads_count', 1)
                ->has('leads.data', 1));
    }

    public function test_deleting_a_product_removes_its_lead_matches(): void
    {
        $product = Product::factory()->create();
        $lead = Lead::factory()->create();
        $match = $lead->productMatches()->create(['product_id' => $product->id]);

        $this->delete(route('products.destroy', $product))->assertRedirect();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('lead_product_matches', ['id' => $match->id]);
        // The lead itself survives.
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    public function test_to_lines_trims_and_drops_blank_entries(): void
    {
        $this->assertSame(
            ['Alpha', 'Beta'],
            Product::toLines("  Alpha  \n\n  Beta \n \n"),
        );

        $this->assertSame([], Product::toLines(null));
        $this->assertSame([], Product::toLines(''));
    }
}
