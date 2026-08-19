<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Http\Requests\ImportCsvRequest;
use App\Services\CsvImporter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CSV import for Companies + Contacts + Leads in one pass.
 *
 * Two steps by design: preview() validates and reports without writing, and
 * store() commits only after the user has seen that preview. The file is read
 * in-process and never persisted or transmitted.
 */
class ImportController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Import/Index', [
            'template' => $this->templateDefinition(),
            'preview' => null,
        ]);
    }

    /**
     * Analyse the upload and re-render the same page with the result.
     *
     * Rendered directly rather than flashed to the session: the analysis of a
     * large file is far too big to belong in a session cookie/file, and nothing
     * about the upload should outlive the request.
     */
    public function preview(ImportCsvRequest $request, CsvImporter $importer): Response
    {
        return Inertia::render('Import/Index', [
            'template' => $this->templateDefinition(),
            'preview' => $importer->analyse($request->file('file')),
        ]);
    }

    public function store(ImportCsvRequest $request, CsvImporter $importer): RedirectResponse
    {
        $result = $importer->import(
            $request->file('file'),
            $request->boolean('import_duplicates'),
        );

        if ($result['imported'] === 0) {
            return back()->with('error', 'Nothing was imported. '.$result['skipped'].' row(s) skipped.');
        }

        return to_route('leads.index')->with(
            'success',
            "Imported {$result['imported']} lead(s): {$result['companies']} new "
            ."compan(ies), {$result['contacts']} new contact(s). {$result['skipped']} row(s) skipped."
        );
    }

    /**
     * A blank CSV with the expected headers, generated locally.
     */
    public function template(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $columns = $this->templateDefinition()['columns'];

        return response()->streamDownload(function () use ($columns): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $columns, escape: '');
            fclose($handle);
        }, 'sales-copilot-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The expected header set and the values the importer accepts. Shared by the
     * page, the preview and the downloadable template so they cannot drift.
     *
     * @return array{columns: list<string>, statuses: list<string>, priorities: list<string>, maxRows: int}
     */
    private function templateDefinition(): array
    {
        return [
            'columns' => [
                'Company Name', 'Website', 'Country', 'State', 'City',
                'Contact First Name', 'Contact Last Name', 'Job Title', 'Department',
                'Email', 'Phone', 'LinkedIn',
                'Industry', 'Company Type',
                'Lead Source', 'Lead Status', 'Priority', 'Notes',
            ],
            'statuses' => LeadStatus::values(),
            'priorities' => Priority::values(),
            'maxRows' => CsvImporter::MAX_ROWS,
        ];
    }
}
