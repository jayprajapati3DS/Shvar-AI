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

            // One filled example row. Header-only templates leave people
            // guessing how to write the multi-value fields, and the newline
            // convention for Specialties is not obvious from a bare header.
            fputcsv($handle, $this->exampleRow(), escape: '');

            fclose($handle);
        }, 'shvar-ai-copilot-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * A realistic example row for the downloadable template.
     *
     * Deliberately an invented company on a .example domain (RFC 2606, cannot
     * resolve) so nobody imports it as a real lead by accident.
     *
     * @return list<string>
     */
    private function exampleRow(): array
    {
        return [
            // Company
            'Northfield Orthopedic Devices',
            'northfield-ortho.example',
            'Medical Devices',
            'PSI Manufacturer',
            'United States',
            'Minnesota',
            'Minneapolis',
            'Designs patient-specific knee and hip instrumentation from CT imaging.',
            // Multi-value fields accept one per line, or comma/semicolon separated.
            "Knee
Hip",
            'Patient-specific cutting guides, revision instrumentation',
            'Met at a trade show; evaluating in-house planning software.',

            // Contact
            'Dana',
            'Whitfield',
            'Director of Engineering',
            'R&D',
            'dana.whitfield@northfield-ortho.example',
            '+1 612 555 0142',
            'linkedin.com/in/example',
            'United States',
            'Minneapolis',
            'Prefers email over calls.',

            // Lead
            'Conference',
            'Qualified',
            'High',
            'Jay Prajapati',
            'Outsources segmentation today; wants it in-house.',
        ];
    }

    /**
     * The expected header set and the values the importer accepts. Shared by the
     * page, the preview and the downloadable template so they cannot drift.
     *
     * @return array{columns: list<string>, groups: array<string, list<string>>, statuses: list<string>, priorities: list<string>, maxRows: int}
     */
    private function templateDefinition(): array
    {
        return [
            // Every field on the company, contact and lead forms, so an import
            // can carry the same information you would type by hand. Grouped so
            // the downloaded template reads in a sensible order.
            'columns' => [
                // Company
                'Company Name', 'Website', 'Industry', 'Company Type',
                'Country', 'State', 'City',
                'Description', 'Specialties', 'Products Services', 'Company Notes',

                // Contact
                'Contact First Name', 'Contact Last Name', 'Job Title', 'Department',
                'Email', 'Phone', 'LinkedIn', 'Contact Country', 'Contact City', 'Contact Notes',

                // Lead
                'Lead Source', 'Lead Status', 'Priority', 'Assigned To', 'Notes',
            ],

            // Shown on the page so it is obvious which column feeds which record.
            'groups' => [
                'Company' => [
                    'Company Name', 'Website', 'Industry', 'Company Type',
                    'Country', 'State', 'City',
                    'Description', 'Specialties', 'Products Services', 'Company Notes',
                ],
                'Contact' => [
                    'Contact First Name', 'Contact Last Name', 'Job Title', 'Department',
                    'Email', 'Phone', 'LinkedIn', 'Contact Country', 'Contact City', 'Contact Notes',
                ],
                'Lead' => [
                    'Lead Source', 'Lead Status', 'Priority', 'Assigned To', 'Notes',
                ],
            ],
            'statuses' => LeadStatus::values(),
            'priorities' => Priority::values(),
            'maxRows' => CsvImporter::MAX_ROWS,
        ];
    }
}
