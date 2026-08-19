<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\LeadAnalysisResource;
use App\Http\Resources\LeadResource;
use App\Http\Resources\ProductResource;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Product;
use App\Services\AI\AIServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(
        // Only used to tell the lead page whether AI is available, so the
        // Analyze button can explain itself when it is not.
        private readonly AIServiceInterface $ai,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'priority', 'country', 'company_id', 'source']);

        $leads = Lead::query()
            ->with([
                'company:id,name,country,city',
                'contact:id,first_name,last_name,job_title,email',
                'productMatches.product:id,name',
            ])
            ->withCount('productMatches')
            ->search($filters['search'] ?? null)
            ->filter($filters)
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Leads/Index', [
            'leads' => LeadResource::collection($leads),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => LeadStatus::options(),
                'priorities' => Priority::options(),
                'companies' => $this->companyOptions(),
                'countries' => $this->companyCountries(),
                'sources' => $this->sourceOptions(),
            ],
            // The "New lead" modal lives on this page, so it needs the full
            // form payload (contacts included), not just the filter lists.
            'options' => $this->formOptions(),
        ]);
    }

    public function show(Lead $lead): Response
    {
        $lead->load([
            'company',
            'contact.company:id,name',
            // Newest first so the AI's own ordering (priority, then confidence)
            // is preserved within a run while the latest run leads.
            'productMatches' => fn ($q) => $q->with('product')->latest('id'),
            'activities',
        ]);

        // Summaries only - the full detail of a past analysis is fetched on
        // demand, so a lead analysed a dozen times does not bloat this page.
        $analyses = $lead->analyses()
            ->with('primaryProduct:id,name')
            ->withCount('recommendations')
            ->limit(20)
            ->get();

        return Inertia::render('Leads/Show', [
            'lead' => new LeadResource($lead),
            'options' => $this->formOptions(),
            // Products with no ACTIVE opportunity on this lead, for the manual
            // picker. A previously rejected suggestion does not exclude one.
            'availableProducts' => ProductResource::collection(
                Product::active()
                    ->whereDoesntHave('leadMatches', fn ($q) => $q
                        ->where('lead_id', $lead->id)
                        ->active())
                    ->orderBy('name')
                    ->get()
            ),
            'activityTypes' => ActivityType::manualOptions(),

            // PHASE 3: AI Sales Intelligence.
            'analyses' => LeadAnalysisResource::collection($analyses),
            'latestAnalysis' => $lead->analyses()->exists()
                ? new LeadAnalysisResource(
                    $lead->analyses()->with(['recommendations.product', 'primaryProduct'])->first()
                )
                : null,
            'aiStatus' => $this->ai->status()->toArray(),
        ]);
    }

    public function store(StoreLeadRequest $request): RedirectResponse
    {
        $lead = Lead::create($request->validated());

        Activity::record(
            $lead,
            ActivityType::StatusChange,
            'Lead created',
            "Status set to {$lead->lead_status->value}."
        );

        return to_route('leads.show', $lead)->with('success', 'Lead created.');
    }

    public function update(StoreLeadRequest $request, Lead $lead): RedirectResponse
    {
        $previousStatus = $lead->lead_status;

        $lead->update($request->validated());

        // A status move is the one change worth its own timeline entry.
        if ($lead->lead_status !== $previousStatus) {
            Activity::record(
                $lead,
                ActivityType::StatusChange,
                "Status changed to {$lead->lead_status->value}",
                "Previously {$previousStatus->value}."
            );
        }

        return back()->with('success', 'Lead updated.');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        $lead->delete();

        return to_route('leads.index')->with('success', 'Lead deleted.');
    }

    /**
     * Shared payload for the create/edit lead form.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'statuses' => LeadStatus::options(),
            'priorities' => Priority::options(),
            'sources' => LeadSource::options(),
            'companies' => $this->companyOptions(),
            'contacts' => ContactResource::collection(
                Contact::with('company:id,name')->orderBy('first_name')->get()
            ),
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function companyOptions(): array
    {
        return Company::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])
            ->all();
    }

    /** @return list<string> */
    private function companyCountries(): array
    {
        return Company::query()
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->all();
    }

    /**
     * The standard sources plus any ad-hoc value already saved on a lead, so a
     * hand-typed source stays filterable.
     *
     * @return list<array{value: string, label: string}>
     */
    private function sourceOptions(): array
    {
        $used = Lead::query()
            ->whereNotNull('lead_source')
            ->where('lead_source', '!=', '')
            ->distinct()
            ->pluck('lead_source')
            ->all();

        $all = array_values(array_unique([...LeadSource::values(), ...$used]));
        sort($all);

        return array_map(fn (string $s) => ['value' => $s, 'label' => $s], $all);
    }
}
