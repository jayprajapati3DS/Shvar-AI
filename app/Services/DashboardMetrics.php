<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Computes every number shown on the dashboard.
 *
 * All figures come from live queries. Nothing here is sampled, estimated or
 * faked - an empty database yields zeros, and the UI renders an empty state.
 */
class DashboardMetrics
{
    /**
     * Memoised per instance (one per request), not static - a static cache
     * would go stale under a persistent worker such as Octane.
     *
     * @var array<string, int>|null
     */
    private ?array $statusCounts = null;

    /**
     * Headline cards.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $byStatus = $this->leadCountsByStatus();

        $opportunities = collect(LeadStatus::opportunityStages())
            ->sum(fn (LeadStatus $s) => $byStatus[$s->value] ?? 0);

        return [
            'companies' => Company::count(),
            'contacts' => Contact::count(),
            'leads' => array_sum($byStatus),
            'new_leads' => $byStatus[LeadStatus::New->value] ?? 0,
            'qualified_leads' => $byStatus[LeadStatus::Qualified->value] ?? 0,
            'follow_ups' => $byStatus[LeadStatus::FollowUp->value] ?? 0,
            'opportunities' => $opportunities,
            'won' => $byStatus[LeadStatus::Won->value] ?? 0,
        ];
    }

    /**
     * The ordered pipeline used by the dashboard chart.
     *
     * @return list<array{status: string, label: string, count: int, color: string, percentage: float}>
     */
    public function pipeline(): array
    {
        $byStatus = $this->leadCountsByStatus();
        $stages = LeadStatus::pipeline();

        $peak = collect($stages)->max(fn (LeadStatus $s) => $byStatus[$s->value] ?? 0);

        return array_map(fn (LeadStatus $stage) => [
            'status' => $stage->value,
            'label' => $stage->value,
            'count' => $byStatus[$stage->value] ?? 0,
            'color' => $stage->color(),
            // Bar width is relative to the busiest stage, so a pipeline of
            // 3 leads is still readable. Zero peak means an empty pipeline.
            'percentage' => $peak > 0 ? round((($byStatus[$stage->value] ?? 0) / $peak) * 100, 1) : 0.0,
        ], $stages);
    }

    /**
     * Leads touched most recently, for the dashboard activity panel.
     *
     * @return \Illuminate\Support\Collection<int, Lead>
     */
    public function recentLeads(int $limit = 8)
    {
        return Lead::query()
            ->with(['company:id,name,country', 'contact:id,first_name,last_name,job_title'])
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Lead counts grouped by priority, for the dashboard breakdown.
     *
     * @return array<string, int>
     */
    public function byPriority(): array
    {
        return Lead::query()
            ->select('priority', DB::raw('count(*) as aggregate'))
            ->groupBy('priority')
            ->pluck('aggregate', 'priority')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * One grouped query reused by summary() and pipeline() so the dashboard
     * does not fire a COUNT per status.
     *
     * @return array<string, int>
     */
    private function leadCountsByStatus(): array
    {
        return $this->statusCounts ??= Lead::query()
            ->select('lead_status', DB::raw('count(*) as aggregate'))
            ->groupBy('lead_status')
            ->pluck('aggregate', 'lead_status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
