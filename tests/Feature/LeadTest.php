<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Enums\RecommendationType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_leads_with_company_contact_and_products(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Medical']);
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $lead = Lead::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);

        $product = Product::factory()->create(['name' => 'MySegmenter Test']);
        $lead->productMatches()->create(['product_id' => $product->id]);

        $this->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leads/Index')
                ->has('leads.data', 1)
                ->where('leads.data.0.company.name', 'Acme Medical')
                ->where('leads.data.0.products_count', 1)
                ->where('leads.data.0.product_summary.0', 'MySegmenter Test'));
    }

    public function test_index_filters_by_status_priority_and_country(): void
    {
        $indian = Company::factory()->create(['country' => 'India']);
        $german = Company::factory()->create(['country' => 'Germany']);

        Lead::factory()->status(LeadStatus::Qualified)->priority(Priority::High)
            ->create(['company_id' => $indian->id]);
        Lead::factory()->status(LeadStatus::Lost)->priority(Priority::Low)
            ->create(['company_id' => $german->id]);

        $this->get(route('leads.index', ['status' => 'Qualified']))
            ->assertInertia(fn (Assert $page) => $page->has('leads.data', 1));

        $this->get(route('leads.index', ['priority' => 'High']))
            ->assertInertia(fn (Assert $page) => $page->has('leads.data', 1));

        $this->get(route('leads.index', ['country' => 'Germany']))
            ->assertInertia(fn (Assert $page) => $page->has('leads.data', 1));
    }

    public function test_index_search_matches_company_and_contact(): void
    {
        $company = Company::factory()->create(['name' => 'Findable Devices']);
        $contact = Contact::factory()->create(['company_id' => $company->id, 'email' => 'target@findable.example']);
        Lead::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);
        Lead::factory()->create();

        $this->get(route('leads.index', ['search' => 'Findable Devices']))
            ->assertInertia(fn (Assert $page) => $page->has('leads.data', 1));

        $this->get(route('leads.index', ['search' => 'target@findable.example']))
            ->assertInertia(fn (Assert $page) => $page->has('leads.data', 1));
    }

    public function test_a_lead_can_be_created_and_logs_an_activity(): void
    {
        $company = Company::factory()->create();

        $this->post(route('leads.store'), [
            'company_id' => $company->id,
            'lead_status' => LeadStatus::New->value,
            'priority' => Priority::High->value,
            'lead_source' => 'LinkedIn',
        ])->assertRedirect();

        $lead = Lead::sole();

        $this->assertSame(LeadStatus::New, $lead->lead_status);
        $this->assertDatabaseHas('activities', [
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'type' => ActivityType::StatusChange->value,
            'title' => 'Lead created',
        ]);
    }

    public function test_a_lead_requires_a_company_or_a_contact(): void
    {
        $this->post(route('leads.store'), [
            'lead_status' => LeadStatus::New->value,
            'priority' => Priority::Medium->value,
        ])->assertSessionHasErrors('company_id');
    }

    public function test_a_contact_from_another_company_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $contactB = Contact::factory()->create(['company_id' => $companyB->id]);

        $this->post(route('leads.store'), [
            'company_id' => $companyA->id,
            'contact_id' => $contactB->id,
            'lead_status' => LeadStatus::New->value,
            'priority' => Priority::Medium->value,
        ])->assertSessionHasErrors('contact_id');
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $company = Company::factory()->create();

        $this->post(route('leads.store'), [
            'company_id' => $company->id,
            'lead_status' => 'Definitely Not A Status',
            'priority' => Priority::Medium->value,
        ])->assertSessionHasErrors('lead_status');
    }

    public function test_a_status_change_is_recorded_on_the_timeline(): void
    {
        $lead = Lead::factory()->status(LeadStatus::New)->create();

        $this->put(route('leads.update', $lead), [
            'company_id' => $lead->company_id,
            'lead_status' => LeadStatus::Qualified->value,
            'priority' => $lead->priority->value,
        ])->assertRedirect();

        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->lead_status);
        $this->assertDatabaseHas('activities', [
            'subject_id' => $lead->id,
            'title' => 'Status changed to Qualified',
        ]);
    }

    public function test_an_unchanged_status_does_not_add_a_timeline_entry(): void
    {
        $lead = Lead::factory()->status(LeadStatus::New)->create();
        $before = $lead->activities()->count();

        $this->put(route('leads.update', $lead), [
            'company_id' => $lead->company_id,
            'lead_status' => LeadStatus::New->value,
            'priority' => $lead->priority->value,
            'notes' => 'Just a note change.',
        ]);

        $this->assertSame($before, $lead->fresh()->activities()->count());
    }

    public function test_a_product_can_be_attached_manually_and_detached(): void
    {
        $lead = Lead::factory()->create();
        $product = Product::factory()->create();

        $this->post(route('leads.products.store', $lead), [
            'product_id' => $product->id,
            'reason' => 'They manufacture PSI and need segmentation.',
        ])->assertRedirect();

        $match = $lead->productMatches()->sole();

        // Phase 1 always writes a Manual match with no confidence score.
        $this->assertSame(RecommendationType::Manual, $match->recommendation_type);
        $this->assertNull($match->confidence_score);

        $this->delete(route('leads.products.destroy', [$lead, $match]))->assertRedirect();
        $this->assertDatabaseMissing('lead_product_matches', ['id' => $match->id]);
    }

    public function test_the_same_product_cannot_be_attached_twice(): void
    {
        $lead = Lead::factory()->create();
        $product = Product::factory()->create();
        $lead->productMatches()->create(['product_id' => $product->id]);

        $this->post(route('leads.products.store', $lead), ['product_id' => $product->id])
            ->assertSessionHasErrors('product_id');
    }

    public function test_a_match_belonging_to_another_lead_cannot_be_detached(): void
    {
        $leadA = Lead::factory()->create();
        $leadB = Lead::factory()->create();
        $product = Product::factory()->create();
        $match = $leadB->productMatches()->create(['product_id' => $product->id]);

        $this->delete(route('leads.products.destroy', [$leadA, $match]))->assertNotFound();
        $this->assertDatabaseHas('lead_product_matches', ['id' => $match->id]);
    }

    public function test_show_excludes_already_attached_and_inactive_products(): void
    {
        $lead = Lead::factory()->create();
        $attached = Product::factory()->create();
        Product::factory()->create();                 // available
        Product::factory()->inactive()->create();     // hidden: inactive
        $lead->productMatches()->create(['product_id' => $attached->id]);

        $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Leads/Show')
                ->has('lead.data.product_matches', 1)
                ->has('availableProducts.data', 1));
    }

    public function test_deleting_a_lead_removes_its_matches_and_activities(): void
    {
        $lead = Lead::factory()->create();
        $product = Product::factory()->create();
        $match = $lead->productMatches()->create(['product_id' => $product->id]);
        $activity = $lead->activities()->create([
            'type' => ActivityType::Note,
            'title' => 'A note',
            'occurred_at' => now(),
        ]);

        $this->delete(route('leads.destroy', $lead))->assertRedirect();

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
        $this->assertDatabaseMissing('lead_product_matches', ['id' => $match->id]);

        // A polymorphic relation carries no database FK, so the HasActivities
        // trait is what stops these rows being orphaned.
        $this->assertDatabaseMissing('activities', ['id' => $activity->id]);
    }
}
