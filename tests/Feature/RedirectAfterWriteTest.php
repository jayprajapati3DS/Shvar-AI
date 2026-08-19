<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where you end up after saving something.
 *
 * Two bugs this pins, both of which made the app feel like it was throwing you
 * around:
 *
 *   1. `back()` falls back to the SITE ROOT when there is no previous URL -
 *      after a hard refresh, a fresh session, any request without a referer.
 *      So renaming a product could drop you on the dashboard.
 *
 *   2. Create actions redirected to the new record's detail page. The create
 *      form is a modal ON the list, so saving threw you off the list you were
 *      working through - adding three people to one company meant navigating
 *      back twice.
 *
 * The rule: stay exactly where you were, and when that is genuinely unknown,
 * fall back to the module's own index. Never the dashboard.
 */
class RedirectAfterWriteTest extends TestCase
{
    use RefreshDatabase;

    private function dashboard(): string
    {
        return route('dashboard');
    }

    /* ------------------------------------------------------------------ */
    /* Creating keeps you where you were */
    /* ------------------------------------------------------------------ */

    public function test_creating_a_lead_from_the_list_keeps_you_on_the_list(): void
    {
        $company = Company::factory()->create();

        $this->from(route('leads.index'))
            ->post(route('leads.store'), [
                'company_id' => $company->id,
                'first_name' => 'Dana',
                'lead_status' => LeadStatus::New->value,
                'priority' => Priority::Medium->value,
            ])
            ->assertRedirect(route('leads.index'));
    }

    public function test_creating_a_lead_from_a_company_keeps_you_on_that_company(): void
    {
        $company = Company::factory()->create();

        // Building out an account is the reason you were on that page. Being
        // thrown to the leads list mid-way is the opposite of helpful.
        $this->from(route('companies.show', $company))
            ->post(route('leads.store'), [
                'company_id' => $company->id,
                'first_name' => 'Dana',
                'lead_status' => LeadStatus::New->value,
                'priority' => Priority::Medium->value,
            ])
            ->assertRedirect(route('companies.show', $company));
    }

    public function test_creating_a_company_keeps_you_on_the_company_list(): void
    {
        $this->from(route('companies.index'))
            ->post(route('companies.store'), ['name' => 'Acme Medical'])
            ->assertRedirect(route('companies.index'));
    }

    public function test_creating_a_product_keeps_you_on_the_product_list(): void
    {
        $this->from(route('products.index'))
            ->post(route('products.store'), ['name' => 'MySegmenter', 'active' => true])
            ->assertRedirect(route('products.index'));
    }

    /* ------------------------------------------------------------------ */
    /* Editing keeps you where you were */
    /* ------------------------------------------------------------------ */

    public function test_editing_a_lead_from_its_detail_page_keeps_you_there(): void
    {
        $lead = Lead::factory()->create();

        $this->from(route('leads.show', $lead))
            ->put(route('leads.update', $lead), [
                'company_id' => $lead->company_id,
                'first_name' => 'Renamed',
                'lead_status' => LeadStatus::Qualified->value,
                'priority' => Priority::High->value,
            ])
            ->assertRedirect(route('leads.show', $lead));
    }

    public function test_editing_a_lead_from_the_list_keeps_you_on_the_list(): void
    {
        $lead = Lead::factory()->create();

        $this->from(route('leads.index'))
            ->put(route('leads.update', $lead), [
                'company_id' => $lead->company_id,
                'first_name' => 'Renamed',
                'lead_status' => LeadStatus::Qualified->value,
                'priority' => Priority::High->value,
            ])
            ->assertRedirect(route('leads.index'));
    }

    /* ------------------------------------------------------------------ */
    /* Never the dashboard */
    /* ------------------------------------------------------------------ */

    public function test_editing_a_product_with_no_referer_falls_back_to_products(): void
    {
        $product = Product::factory()->create();

        // No ->from(), so there is no previous URL at all - exactly what
        // happens after a hard refresh or in a fresh session. This used to
        // land on the dashboard.
        $response = $this->put(route('products.update', $product), [
            'name' => 'Renamed',
            'active' => true,
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertNotSame($this->dashboard(), $response->headers->get('Location'));
    }

    public function test_editing_a_company_with_no_referer_falls_back_to_companies(): void
    {
        $company = Company::factory()->create();

        $response = $this->put(route('companies.update', $company), ['name' => 'Renamed Ltd']);

        $response->assertRedirect(route('companies.index'));
        $this->assertNotSame($this->dashboard(), $response->headers->get('Location'));
    }

    public function test_creating_a_lead_with_no_referer_falls_back_to_leads(): void
    {
        $company = Company::factory()->create();

        $this->post(route('leads.store'), [
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'lead_status' => LeadStatus::New->value,
            'priority' => Priority::Medium->value,
        ])->assertRedirect(route('leads.index'));
    }

    /* ------------------------------------------------------------------ */
    /* Deleting */
    /* ------------------------------------------------------------------ */

    public function test_deleting_from_a_detail_page_goes_to_the_list(): void
    {
        $lead = Lead::factory()->create();

        // The page it was deleted from no longer exists, so staying is not an
        // option - but the module index is, and the dashboard is not.
        $this->from(route('leads.show', $lead))
            ->delete(route('leads.destroy', $lead))
            ->assertRedirect(route('leads.index'));
    }

    public function test_deleting_from_the_list_keeps_you_on_the_list(): void
    {
        $lead = Lead::factory()->create();

        $this->from(route('leads.index'))
            ->delete(route('leads.destroy', $lead))
            ->assertRedirect(route('leads.index'));
    }

    /* ------------------------------------------------------------------ */
    /* Bulk actions */
    /* ------------------------------------------------------------------ */

    public function test_a_bulk_edit_keeps_you_on_the_list(): void
    {
        $leads = Lead::factory()->count(2)->create();

        $this->from(route('leads.index', ['status' => 'New']))
            ->post(route('leads.bulk-update'), [
                'ids' => $leads->pluck('id')->all(),
                'values' => ['priority' => Priority::High->value],
            ])
            // Filters included: a bulk edit should not throw away the view you
            // built to select the records in the first place.
            ->assertRedirect(route('leads.index', ['status' => 'New']));
    }

    /* ------------------------------------------------------------------ */
    /* The boundary */
    /* ------------------------------------------------------------------ */

    public function test_an_offsite_referer_is_not_followed(): void
    {
        $product = Product::factory()->create();

        // Only ever bounce somewhere inside this application, whatever the
        // browser claims the previous page was.
        $response = $this->from('https://example.com/somewhere')
            ->put(route('products.update', $product), ['name' => 'Renamed', 'active' => true]);

        $response->assertRedirect(route('products.index'));
    }
}
