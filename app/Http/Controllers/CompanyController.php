<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Requests\BulkCompanyActionRequest;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Resources\CompanyAnalysisResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\ProductResource;
use App\Models\Company;
use App\Services\AI\Research\CompanyResearcher;
use App\Services\AI\Research\CompanyResearchSchema;
use App\Services\BulkEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    use RedirectsToOrigin;

    public function __construct(
        // Only used to tell the page whether research is switched on, so the
        // button can explain itself rather than failing on click.
        private readonly CompanyResearcher $researcher,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'country', 'industry', 'company_type']);

        $companies = Company::query()
            ->withCount(['leads', 'contactableLeads'])
            ->search($filters['search'] ?? null)
            ->when($filters['country'] ?? null, fn ($q, $v) => $q->where('country', $v))
            ->when($filters['industry'] ?? null, fn ($q, $v) => $q->where('industry', $v))
            ->when($filters['company_type'] ?? null, fn ($q, $v) => $q->where('company_type', $v))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Companies/Index', [
            'companies' => CompanyResource::collection($companies),
            'filters' => $filters,
            'filterOptions' => [
                'countries' => $this->distinct('country'),
                'industries' => $this->distinct('industry'),
                'companyTypes' => $this->distinct('company_type'),
            ],
            // Drives the bulk-edit modal. Sent from the model so the form and
            // the validation can never describe different fields.
            'bulkFields' => Company::bulkFieldsForUi(),
        ]);
    }

    public function show(Company $company): Response
    {
        $company->load([
            'leads' => fn ($q) => $q->orderBy('first_name')->orderBy('id'),
            'leads.company:id,name',
            'activities',
        ]);

        // Summaries plus the newest run in full - the same shape as the lead
        // page, so a company researched a dozen times does not bloat this page.
        $analyses = $company->analyses()->limit(20)->get();

        return Inertia::render('Companies/Show', [
            'company' => new CompanyResource($company),
            // Products reached through this company's leads.
            'products' => ProductResource::collection($company->associatedProducts()),

            // Website research.
            'analyses' => CompanyAnalysisResource::collection($analyses),
            'latestAnalysis' => $analyses->first()
                ? new CompanyAnalysisResource($analyses->first())
                : null,
            'research' => [
                'enabled' => $this->researcher->enabled(),
                'fields' => CompanyResearchSchema::FIELDS,
            ],
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = Company::create($request->validated());

        return $this->backTo('companies.index')
            ->with('success', "Company \"{$company->name}\" created.");
    }

    public function update(StoreCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        return $this->backTo('companies.index')->with('success', "Company \"{$company->name}\" updated.");
    }

    public function destroy(Company $company): RedirectResponse
    {
        $name = $company->name;

        // Contacts and leads survive with a null company_id (nullOnDelete)
        // rather than disappearing with the company.
        $company->delete();

        return to_route('companies.index')
            ->with('success', "Company \"{$name}\" deleted. The people you were working there were kept as leads.");
    }

    public function bulkUpdate(BulkCompanyActionRequest $request, BulkEditor $editor): RedirectResponse
    {
        $changed = $editor->update(
            Company::class,
            $request->ids(),
            $request->values(),
            $request->clear(),
        );

        return $this->backTo('companies.index')->with(
            $changed > 0 ? 'success' : 'error',
            $changed > 0
                ? "Updated {$changed} compan(ies)."
                : 'Nothing to update - no fields were changed.'
        );
    }

    public function bulkDestroy(BulkCompanyActionRequest $request, BulkEditor $editor): RedirectResponse
    {
        $deleted = $editor->delete(Company::class, $request->ids());

        return $this->backTo('companies.index')->with(
            'success',
            "Deleted {$deleted} compan(ies). The people you were working there were kept as leads."
        );
    }

    /**
     * Distinct non-empty values of a column, for the filter dropdowns.
     *
     * @return list<string>
     */
    private function distinct(string $column): array
    {
        return Company::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
