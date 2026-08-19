<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_database_yields_zeros_not_placeholders(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('summary.companies', 0)
                ->where('summary.contacts', 0)
                ->where('summary.leads', 0)
                ->where('summary.opportunities', 0)
                ->where('summary.won', 0)
                ->has('recentLeads.data', 0)
                // The pipeline still renders every stage, all at zero.
                ->has('pipeline', 10));
    }

    public function test_summary_counts_come_from_real_records(): void
    {
        // Every lead is pinned to one shared company so the company count is
        // exactly what this test creates, not whatever the factory implies.
        $company = Company::factory()->create();
        Contact::factory()->count(2)->create(['company_id' => $company->id]);

        $make = fn (LeadStatus $status, int $count = 1) => Lead::factory()
            ->count($count)
            ->status($status)
            ->create(['company_id' => $company->id]);

        $make(LeadStatus::New, 4);
        $make(LeadStatus::Qualified, 2);
        $make(LeadStatus::FollowUp);
        $make(LeadStatus::Won);

        // Opportunity stages: Interested, Meeting Scheduled, Proposal, Negotiation.
        $make(LeadStatus::Interested);
        $make(LeadStatus::Proposal);
        $make(LeadStatus::Negotiation);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.companies', 1)
                ->where('summary.contacts', 2)
                ->where('summary.leads', 11)
                ->where('summary.new_leads', 4)
                ->where('summary.qualified_leads', 2)
                ->where('summary.follow_ups', 1)
                ->where('summary.opportunities', 3)
                ->where('summary.won', 1));
    }

    public function test_pipeline_percentages_scale_to_the_busiest_stage(): void
    {
        Lead::factory()->count(4)->status(LeadStatus::New)->create();
        Lead::factory()->count(2)->status(LeadStatus::Qualified)->create();

        $this->get(route('dashboard'))
            ->assertInertia(function (Assert $page) {
                $pipeline = collect($page->toArray()['props']['pipeline']);

                $new = $pipeline->firstWhere('status', 'New');
                $qualified = $pipeline->firstWhere('status', 'Qualified');
                $contacted = $pipeline->firstWhere('status', 'Contacted');

                $this->assertSame(4, $new['count']);
                $this->assertEquals(100, $new['percentage']);

                $this->assertSame(2, $qualified['count']);
                $this->assertEquals(50, $qualified['percentage']);

                $this->assertSame(0, $contacted['count']);
                $this->assertEquals(0, $contacted['percentage']);
            });
    }

    public function test_priority_breakdown_is_grouped_correctly(): void
    {
        Lead::factory()->count(2)->priority(Priority::High)->create();
        Lead::factory()->priority(Priority::Low)->create();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('byPriority.High', 2)
                ->where('byPriority.Low', 1));
    }

    public function test_recent_leads_are_ordered_by_last_update(): void
    {
        $older = Lead::factory()->create(['updated_at' => now()->subDays(3)]);
        $newer = Lead::factory()->create(['updated_at' => now()]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('recentLeads.data.0.id', $newer->id)
                ->where('recentLeads.data.1.id', $older->id));
    }
}
