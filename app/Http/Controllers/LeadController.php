<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Enums\RecommendationStatus;
use App\Http\Requests\BulkLeadActionRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\EmailDraftResource;
use App\Http\Resources\EmailGenerationResource;
use App\Http\Resources\LeadAnalysisResource;
use App\Http\Resources\LeadResource;
use App\Http\Resources\ProductResource;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailDraft;
use App\Models\EmailGeneration;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\AI\AIServiceInterface;
use App\Services\BulkEditor;
use App\Services\Email\EmailContextBuilder;
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

        // Phase 4: computes which fields are still blank, for the
        // "additional information may improve personalization" nudge.
        private readonly EmailContextBuilder $emailContext,
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
            'bulkFields' => Lead::bulkFieldsForUi(),
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

            // PHASE 4: email outreach.
            'email' => $this->emailPanel($lead),
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
     * Apply one set of changes to many leads.
     *
     * The status-change activity entry is logged per lead exactly as a single
     * edit would - a bulk move through the pipeline should leave the same trail
     * as doing it one at a time, or the timeline quietly lies.
     */
    public function bulkUpdate(BulkLeadActionRequest $request, BulkEditor $editor): RedirectResponse
    {
        $changed = $editor->update(
            Lead::class,
            $request->ids(),
            $request->values(),
            $request->clear(),
            function (Lead $lead, array $attributes): void {
                if (! array_key_exists('lead_status', $attributes)) {
                    return;
                }

                $next = $attributes['lead_status'];

                if ($lead->lead_status->value === $next) {
                    return;
                }

                Activity::record(
                    $lead,
                    ActivityType::StatusChange,
                    "Status changed to {$next}",
                    "Previously {$lead->lead_status->value}. Changed as part of a bulk update."
                );
            },
        );

        return back()->with(
            $changed > 0 ? 'success' : 'error',
            $changed > 0
                ? "Updated {$changed} lead(s)."
                : 'Nothing to update - no fields were changed.'
        );
    }

    public function bulkDestroy(BulkLeadActionRequest $request, BulkEditor $editor): RedirectResponse
    {
        $deleted = $editor->delete(Lead::class, $request->ids());

        return back()->with('success', "Deleted {$deleted} lead(s).");
    }

    /**
     * Everything the Email Outreach panel on the lead page needs.
     *
     * The blockers are computed here rather than inferred in the UI, so the
     * button explains itself instead of failing on click - and so there is one
     * definition of "can this lead be written to", shared with the controller
     * that actually enforces it.
     *
     * @return array<string, mixed>
     */
    private function emailPanel(Lead $lead): array
    {
        $lead->loadMissing(['company', 'contact']);

        $drafts = EmailDraft::query()
            ->where('lead_id', $lead->id)
            ->with('product:id,name')
            ->latestFirst()
            ->limit(30)
            ->get();

        $generations = EmailGeneration::query()
            ->where('lead_id', $lead->id)
            ->with('recommendations.product:id,name')
            ->latestFirst()
            ->limit(10)
            ->get();

        // Only ACCEPTED recommendations. Section 5: the email follows the sales
        // direction the user approved, not an unreviewed suggestion.
        $accepted = $lead->productMatches()
            ->where('status', RecommendationStatus::Accepted->value)
            ->whereHas('product', fn ($q) => $q->where('active', true))
            ->with('product:id,name')
            ->get();

        $blockers = [];

        if ($lead->contact === null) {
            $blockers[] = 'This lead has no contact. Add the person you want to write to.';
        } elseif (blank($lead->contact->email)) {
            $blockers[] = 'That contact has no email address.';
        }

        if ($lead->company === null) {
            $blockers[] = 'This lead has no company.';
        }

        if ($accepted->isEmpty()) {
            $blockers[] = 'No accepted product recommendation yet. Run an analysis and accept one, '
                .'or attach a product by hand and accept it.';
        }

        // The soft nudge, only meaningful once generation is actually possible.
        $thin = $blockers === [] && $accepted->isNotEmpty()
            ? $this->emailContext->gaps($lead, $accepted->first())
            : [];

        return [
            'drafts' => EmailDraftResource::collection($drafts)->resolve(),
            'generations' => EmailGenerationResource::collection($generations)->resolve(),
            'acceptedProducts' => $accepted
                ->map(fn (LeadProductMatch $m) => [
                    'id' => $m->id,
                    'product' => $m->product?->name ?? 'Unknown product',
                    'sales_angle' => $m->sales_angle,
                ])
                ->values()
                ->all(),
            'canGenerate' => $blockers === [],
            'blockers' => $blockers,
            'thin' => $thin,
        ];
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
