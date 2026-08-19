<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk edit and bulk delete across the list pages.
 *
 * The interesting cases here are not the happy paths - they are the ones where
 * a bulk write could quietly do more than it was asked to: touching a field
 * nobody selected, accepting a column the model never declared, or emptying
 * something because the input happened to be blank.
 */
class BulkActionsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ updating

    public function test_bulk_update_sets_a_field_on_every_selected_lead(): void
    {
        $leads = Lead::factory()->count(3)->status(LeadStatus::New)->create();

        $this->post(route('leads.bulk-update'), [
            'ids' => $leads->pluck('id')->all(),
            'values' => ['lead_status' => LeadStatus::Qualified->value],
        ])->assertRedirect();

        foreach ($leads as $lead) {
            $this->assertSame(LeadStatus::Qualified, $lead->fresh()->lead_status);
        }
    }

    public function test_bulk_update_leaves_unlisted_fields_alone(): void
    {
        $lead = Lead::factory()->create([
            'lead_status' => LeadStatus::New->value,
            'priority' => Priority::High->value,
            'assigned_to' => 'Jay',
            'notes' => 'Original notes.',
        ]);

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => ['lead_status' => LeadStatus::Contacted->value],
        ])->assertRedirect();

        $lead->refresh();

        $this->assertSame(LeadStatus::Contacted, $lead->lead_status);
        $this->assertSame(Priority::High, $lead->priority);
        $this->assertSame('Jay', $lead->assigned_to);
        $this->assertSame('Original notes.', $lead->notes);
    }

    public function test_a_blank_value_is_not_treated_as_a_request_to_clear(): void
    {
        // The bug this guards: an untouched input posts an empty string, and a
        // naive implementation writes that over a hundred rows.
        $lead = Lead::factory()->create(['assigned_to' => 'Jay']);

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => [
                'assigned_to' => '',
                'lead_status' => LeadStatus::Qualified->value,
            ],
        ])->assertRedirect();

        $this->assertSame('Jay', $lead->fresh()->assigned_to);
    }

    public function test_clearing_a_field_requires_naming_it_explicitly(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => 'Jay']);

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => [],
            'clear' => ['assigned_to'],
        ])->assertRedirect();

        $this->assertNull($lead->fresh()->assigned_to);
    }

    public function test_a_required_field_cannot_be_cleared(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Qualified)->create();

        // lead_status is not declared nullable, so it is not even a valid
        // member of `clear` - the request is rejected rather than half-applied.
        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'clear' => ['lead_status'],
        ])->assertSessionHasErrors('clear.0');

        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->lead_status);
    }

    public function test_setting_and_clearing_the_same_field_is_rejected(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => 'Jay']);

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => ['assigned_to' => 'Dana'],
            'clear' => ['assigned_to'],
        ])->assertSessionHasErrors('values.assigned_to');

        $this->assertSame('Jay', $lead->fresh()->assigned_to);
    }

    public function test_a_field_the_model_does_not_expose_is_ignored(): void
    {
        // `notes` is per-lead by nature and is deliberately absent from
        // bulkEditableFields(). Posting it must not write it.
        $lead = Lead::factory()->create(['notes' => 'Original notes.']);

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => [
                'notes' => 'Injected.',
                'priority' => Priority::Low->value,
            ],
        ])->assertRedirect();

        $lead->refresh();

        $this->assertSame('Original notes.', $lead->notes);
        $this->assertSame(Priority::Low, $lead->priority);
    }

    public function test_an_invalid_enum_value_is_rejected(): void
    {
        $lead = Lead::factory()->status(LeadStatus::New)->create();

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => ['lead_status' => 'Definitely Not A Status'],
        ])->assertSessionHasErrors('values.lead_status');

        $this->assertSame(LeadStatus::New, $lead->fresh()->lead_status);
    }

    public function test_a_status_change_is_logged_on_each_lead_just_as_a_single_edit_would_be(): void
    {
        $leads = Lead::factory()->count(2)->status(LeadStatus::New)->create();

        $this->post(route('leads.bulk-update'), [
            'ids' => $leads->pluck('id')->all(),
            'values' => ['lead_status' => LeadStatus::Qualified->value],
        ])->assertRedirect();

        foreach ($leads as $lead) {
            $activity = Activity::query()
                ->where('subject_type', Lead::class)
                ->where('subject_id', $lead->id)
                ->where('type', ActivityType::StatusChange->value)
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertStringContainsString('Qualified', $activity->title);
            $this->assertStringContainsString('bulk update', $activity->body);
        }
    }

    public function test_no_activity_is_logged_when_the_status_already_matches(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Qualified)->create();
        $before = Activity::query()->where('subject_id', $lead->id)->count();

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => ['lead_status' => LeadStatus::Qualified->value],
        ])->assertRedirect();

        $this->assertSame($before, Activity::query()->where('subject_id', $lead->id)->count());
    }

    public function test_an_edit_that_changes_nothing_reports_an_error_rather_than_success(): void
    {
        $lead = Lead::factory()->create();

        $this->post(route('leads.bulk-update'), [
            'ids' => [$lead->id],
            'values' => [],
        ])->assertSessionHas('error');
    }

    public function test_ids_must_reference_existing_records(): void
    {
        $this->post(route('leads.bulk-update'), [
            'ids' => [999_999],
            'values' => ['priority' => Priority::High->value],
        ])->assertSessionHasErrors('ids.0');
    }

    public function test_an_empty_selection_is_rejected(): void
    {
        $this->post(route('leads.bulk-update'), [
            'ids' => [],
            'values' => ['priority' => Priority::High->value],
        ])->assertSessionHasErrors('ids');
    }

    // ------------------------------------------------------------ deleting

    public function test_bulk_delete_removes_every_selected_record(): void
    {
        $leads = Lead::factory()->count(3)->create();
        $survivor = Lead::factory()->create();

        $this->post(route('leads.bulk-destroy'), [
            'ids' => $leads->pluck('id')->all(),
        ])->assertRedirect();

        foreach ($leads as $lead) {
            $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
        }

        $this->assertDatabaseHas('leads', ['id' => $survivor->id]);
    }

    public function test_deleting_leads_takes_their_timeline_with_them(): void
    {
        $lead = Lead::factory()->create();
        Activity::record($lead, ActivityType::Note, 'A note');

        $this->assertDatabaseCount('activities', 1);

        $this->post(route('leads.bulk-destroy'), ['ids' => [$lead->id]])->assertRedirect();

        // Model events must still fire on a bulk delete - a query-builder
        // delete would leave these rows orphaned.
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_deleting_companies_keeps_their_contacts_and_leads(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $lead = Lead::factory()->create(['company_id' => $company->id]);

        $this->post(route('companies.bulk-destroy'), ['ids' => [$company->id]])
            ->assertRedirect();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'company_id' => null]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'company_id' => null]);
    }

    public function test_deleting_products_removes_the_opportunities_referencing_them(): void
    {
        $lead = Lead::factory()->create();
        $product = Product::factory()->create();
        $lead->productMatches()->create(['product_id' => $product->id]);

        $this->assertDatabaseCount('lead_product_matches', 1);

        $this->post(route('products.bulk-destroy'), ['ids' => [$product->id]])
            ->assertRedirect();

        $this->assertDatabaseCount('lead_product_matches', 0);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    // -------------------------------------------------- the other resources

    public function test_bulk_update_moves_contacts_to_another_company(): void
    {
        $from = Company::factory()->create();
        $to = Company::factory()->create();
        $contacts = Contact::factory()->count(2)->create(['company_id' => $from->id]);

        $this->post(route('contacts.bulk-update'), [
            'ids' => $contacts->pluck('id')->all(),
            'values' => ['company_id' => $to->id],
        ])->assertRedirect();

        foreach ($contacts as $contact) {
            $this->assertSame($to->id, $contact->fresh()->company_id);
        }
    }

    public function test_a_contact_cannot_be_moved_to_a_company_that_does_not_exist(): void
    {
        $contact = Contact::factory()->create();

        $this->post(route('contacts.bulk-update'), [
            'ids' => [$contact->id],
            'values' => ['company_id' => 999_999],
        ])->assertSessionHasErrors('values.company_id');
    }

    public function test_bulk_update_sets_a_shared_country_on_companies(): void
    {
        $companies = Company::factory()->count(3)->create(['country' => null]);

        $this->post(route('companies.bulk-update'), [
            'ids' => $companies->pluck('id')->all(),
            'values' => ['country' => 'United Kingdom'],
        ])->assertRedirect();

        foreach ($companies as $company) {
            $this->assertSame('United Kingdom', $company->fresh()->country);
        }
    }

    public function test_bulk_update_deactivates_products(): void
    {
        $products = Product::factory()->count(2)->create(['active' => true]);

        $this->post(route('products.bulk-update'), [
            'ids' => $products->pluck('id')->all(),
            'values' => ['active' => false],
        ])->assertRedirect();

        foreach ($products as $product) {
            $this->assertFalse($product->fresh()->active);
        }
    }

    // ------------------------------------------------------- page payloads

    public function test_each_index_page_receives_its_bulk_field_definitions(): void
    {
        Company::factory()->create();

        foreach ([
            'companies.index' => ['industry', 'company_type', 'country', 'state', 'city'],
            'contacts.index' => ['company_id', 'department', 'country', 'city'],
            'leads.index' => ['lead_status', 'priority', 'lead_source', 'assigned_to'],
            'products.index' => ['category', 'active'],
        ] as $route => $expected) {
            $response = $this->get(route($route))->assertOk();

            $fields = $response->viewData('page')['props']['bulkFields'];

            $this->assertSame($expected, array_column($fields, 'key'), "on {$route}");

            // Validation rules must never reach the browser: they can hold rule
            // objects, and the client has no business seeing them.
            foreach ($fields as $field) {
                $this->assertArrayNotHasKey('rules', $field, "on {$route}");
                $this->assertArrayHasKey('nullable', $field, "on {$route}");
            }
        }
    }

    public function test_the_contact_company_dropdown_is_filled_from_real_companies(): void
    {
        $company = Company::factory()->create(['name' => 'Northfield Ortho']);

        $response = $this->get(route('contacts.index'))->assertOk();

        $fields = collect($response->viewData('page')['props']['bulkFields'])
            ->keyBy('key');

        $this->assertSame(
            [['value' => $company->id, 'label' => 'Northfield Ortho']],
            $fields['company_id']['options'],
        );
    }
}
