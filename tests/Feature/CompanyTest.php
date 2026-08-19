<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_companies_with_relation_counts(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Medical']);
        Contact::factory()->count(2)->create(['company_id' => $company->id]);
        Lead::factory()->count(3)->create(['company_id' => $company->id]);

        $this->get(route('companies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Companies/Index')
                ->has('companies.data', 1)
                ->where('companies.data.0.name', 'Acme Medical')
                ->where('companies.data.0.contacts_count', 2)
                ->where('companies.data.0.leads_count', 3));
    }

    public function test_index_can_be_searched_and_filtered(): void
    {
        Company::factory()->create(['name' => 'Orthopedic Innovations', 'country' => 'India']);
        Company::factory()->create(['name' => 'Cardio Systems', 'country' => 'Germany']);

        $this->get(route('companies.index', ['search' => 'Orthopedic']))
            ->assertInertia(fn (Assert $page) => $page->has('companies.data', 1));

        $this->get(route('companies.index', ['country' => 'Germany']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.name', 'Cardio Systems'));
    }

    public function test_a_company_can_be_created(): void
    {
        $this->post(route('companies.store'), [
            'name' => 'Shvar Surgical',
            'website' => 'shvar.example',
            'country' => 'India',
            'industry' => 'Medical Devices',
        ])->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'name' => 'Shvar Surgical',
            // prepareForValidation normalises a bare domain into a URL.
            'website' => 'https://shvar.example',
        ]);
    }

    public function test_company_name_is_required_and_unique(): void
    {
        Company::factory()->create(['name' => 'Duplicate Co']);

        $this->post(route('companies.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->post(route('companies.store'), ['name' => 'Duplicate Co'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_company_can_be_updated(): void
    {
        $company = Company::factory()->create(['name' => 'Old Name']);

        $this->put(route('companies.update', $company), [
            'name' => 'New Name',
            'industry' => 'Hospital',
        ])->assertRedirect();

        $this->assertSame('New Name', $company->fresh()->name);
    }

    public function test_show_returns_contacts_leads_and_associated_products(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $lead = Lead::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);

        $product = Product::factory()->create(['name' => 'MySegmenter Test']);
        $lead->productMatches()->create(['product_id' => $product->id]);

        $this->get(route('companies.show', $company))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Companies/Show')
                ->has('company.data.contacts', 1)
                ->has('company.data.leads', 1)
                ->has('products.data', 1)
                ->where('products.data.0.name', 'MySegmenter Test'));
    }

    public function test_deleting_a_company_keeps_its_contacts_and_leads(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $lead = Lead::factory()->create(['company_id' => $company->id]);

        $this->delete(route('companies.destroy', $company))->assertRedirect();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);

        // nullOnDelete: the records survive, detached from the company.
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'company_id' => null]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'company_id' => null]);
    }

    public function test_duplicate_name_normalisation_ignores_suffixes_and_punctuation(): void
    {
        $this->assertSame(
            Company::normaliseName('Acme Medical, Inc.'),
            Company::normaliseName('acme medical inc'),
        );

        $this->assertNotSame(
            Company::normaliseName('Acme Medical'),
            Company::normaliseName('Acme Dental'),
        );
    }
}
