<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_contacts_with_company_and_latest_lead(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Medical']);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Asha',
            'last_name' => 'Patel',
        ]);
        Lead::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);

        $this->get(route('contacts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Contacts/Index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.full_name', 'Asha Patel')
                ->where('contacts.data.0.company.name', 'Acme Medical')
                ->has('contacts.data.0.leads', 1));
    }

    public function test_index_search_matches_email_and_company_name(): void
    {
        $company = Company::factory()->create(['name' => 'Zebra Implants']);
        Contact::factory()->create(['company_id' => $company->id, 'email' => 'lead@zebra.example']);
        Contact::factory()->create(['email' => 'other@elsewhere.example']);

        $this->get(route('contacts.index', ['search' => 'zebra.example']))
            ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1));

        $this->get(route('contacts.index', ['search' => 'Zebra Implants']))
            ->assertInertia(fn (Assert $page) => $page->has('contacts.data', 1));
    }

    public function test_a_contact_can_be_created_without_a_company(): void
    {
        $this->post(route('contacts.store'), [
            'first_name' => 'Ravi',
            'last_name' => 'Shah',
            'email' => 'ravi@example.com',
            'linkedin_url' => 'linkedin.com/in/ravishah',
        ])->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'Ravi',
            'company_id' => null,
            // prepareForValidation adds the scheme.
            'linkedin_url' => 'https://linkedin.com/in/ravishah',
        ]);
    }

    public function test_first_name_is_required_and_email_must_be_valid(): void
    {
        $this->post(route('contacts.store'), ['first_name' => ''])
            ->assertSessionHasErrors('first_name');

        $this->post(route('contacts.store'), ['first_name' => 'Ok', 'email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    public function test_a_contact_can_be_updated_and_deleted(): void
    {
        $contact = Contact::factory()->create(['first_name' => 'Before']);

        $this->put(route('contacts.update', $contact), [
            'first_name' => 'After',
            'company_id' => $contact->company_id,
        ])->assertRedirect();

        $this->assertSame('After', $contact->fresh()->first_name);

        $this->delete(route('contacts.destroy', $contact))->assertRedirect();
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    public function test_deleting_a_contact_keeps_its_leads(): void
    {
        $contact = Contact::factory()->create();
        $lead = Lead::factory()->create([
            'company_id' => $contact->company_id,
            'contact_id' => $contact->id,
        ]);

        $this->delete(route('contacts.destroy', $contact));

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'contact_id' => null]);
    }

    public function test_full_name_handles_a_missing_last_name(): void
    {
        $contact = Contact::factory()->create(['first_name' => 'Solo', 'last_name' => null]);

        $this->assertSame('Solo', $contact->full_name);
    }
}
