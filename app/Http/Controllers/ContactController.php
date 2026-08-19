<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'company_id', 'department', 'country']);

        $contacts = Contact::query()
            ->with([
                'company:id,name',
                // Newest lead only - the list shows a single status chip per contact.
                'leads' => fn ($q) => $q->latest('updated_at')->limit(1),
            ])
            ->withCount('leads')
            ->search($filters['search'] ?? null)
            ->when($filters['company_id'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['department'] ?? null, fn ($q, $v) => $q->where('department', $v))
            ->when($filters['country'] ?? null, fn ($q, $v) => $q->where('country', $v))
            ->orderBy('first_name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Contacts/Index', [
            'contacts' => ContactResource::collection($contacts),
            'filters' => $filters,
            'filterOptions' => [
                'companies' => $this->companyOptions(),
                'departments' => $this->distinct('department'),
                'countries' => $this->distinct('country'),
            ],
        ]);
    }

    public function show(Contact $contact): Response
    {
        $contact->load([
            'company',
            'leads.company:id,name',
            'leads.contact:id,first_name,last_name',
            'activities',
        ]);

        return Inertia::render('Contacts/Show', [
            'contact' => new ContactResource($contact),
            'companies' => $this->companyOptions(),
        ]);
    }

    public function store(StoreContactRequest $request): RedirectResponse
    {
        $contact = Contact::create($request->validated());

        return to_route('contacts.show', $contact)
            ->with('success', "Contact \"{$contact->full_name}\" created.");
    }

    public function update(StoreContactRequest $request, Contact $contact): RedirectResponse
    {
        $contact->update($request->validated());

        return back()->with('success', "Contact \"{$contact->full_name}\" updated.");
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $name = $contact->full_name;
        $contact->delete();

        return to_route('contacts.index')
            ->with('success', "Contact \"{$name}\" deleted.");
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
    private function distinct(string $column): array
    {
        return Contact::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
