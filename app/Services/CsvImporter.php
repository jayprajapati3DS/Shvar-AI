<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * One-file CSV import that creates a Company, a Contact and a Lead per row.
 *
 * Deliberately two-phase: analyse() parses and validates without touching the
 * database so the UI can show a preview, and import() re-parses and commits.
 * Nothing is written until the user confirms the preview.
 *
 * Everything happens in-process on the local machine; no upload leaves the box.
 */
class CsvImporter
{
    /** Hard cap so a stray 500k-row file cannot exhaust memory. */
    public const MAX_ROWS = 5000;

    /**
     * Accepted header spellings, normalised -> canonical field.
     *
     * Matching is case-insensitive and ignores spaces/underscores, so
     * "Company Name", "company_name" and "COMPANYNAME" all land on the same key.
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        // --- Company ---------------------------------------------------
        'companyname' => 'company_name',
        'company' => 'company_name',
        'website' => 'website',
        'url' => 'website',
        'companywebsite' => 'website',
        'country' => 'country',
        'companycountry' => 'country',
        'state' => 'state',
        'region' => 'state',
        'companystate' => 'state',
        'city' => 'city',
        'companycity' => 'city',
        'industry' => 'industry',
        'companytype' => 'company_type',
        'type' => 'company_type',
        'description' => 'company_description',
        'companydescription' => 'company_description',
        'about' => 'company_description',
        'specialties' => 'company_specialties',
        'companyspecialties' => 'company_specialties',
        'specialities' => 'company_specialties',
        'productsservices' => 'company_products',
        'companyproductsservices' => 'company_products',
        'productsandservices' => 'company_products',
        'whattheysell' => 'company_products',
        'companynotes' => 'company_notes',

        // --- Contact ---------------------------------------------------
        'contactfirstname' => 'first_name',
        'firstname' => 'first_name',
        'contactlastname' => 'last_name',
        'lastname' => 'last_name',
        'jobtitle' => 'job_title',
        'title' => 'job_title',
        'role' => 'job_title',
        'department' => 'department',
        'email' => 'email',
        'contactemail' => 'email',
        'phone' => 'phone',
        'contactphone' => 'phone',
        'linkedin' => 'linkedin_url',
        'linkedinurl' => 'linkedin_url',
        'contactcountry' => 'contact_country',
        'contactcity' => 'contact_city',
        'contactnotes' => 'contact_notes',

        // --- Lead ------------------------------------------------------
        'leadsource' => 'lead_source',
        'source' => 'lead_source',
        'leadstatus' => 'lead_status',
        'status' => 'lead_status',
        'priority' => 'priority',
        'assignedto' => 'assigned_to',
        'owner' => 'assigned_to',

        // Bare "Notes" stays the LEAD note, as it was before these columns
        // existed - an existing template must keep importing the same way.
        'notes' => 'notes',
        'leadnotes' => 'notes',
    ];

    /**
     * Parse + validate + detect duplicates, writing nothing.
     *
     * @return array{
     *     headers: list<string>,
     *     mapped: array<string, string|null>,
     *     unmapped: list<string>,
     *     rows: list<array<string, mixed>>,
     *     total: int,
     *     valid: int,
     *     invalid: int,
     *     duplicates: int,
     *     truncated: bool
     * }
     */
    public function analyse(UploadedFile $file): array
    {
        [$headers, $records] = $this->readCsv($file);

        $mapped = [];
        $unmapped = [];

        foreach ($headers as $header) {
            $field = self::COLUMN_MAP[$this->normaliseHeader($header)] ?? null;
            $mapped[$header] = $field;

            if ($field === null) {
                $unmapped[] = $header;
            }
        }

        // Existing keys pulled once up front rather than a query per row.
        $existingCompanies = Company::pluck('name')
            ->mapWithKeys(fn (string $name) => [Company::normaliseName($name) => true])
            ->all();

        $existingEmails = Contact::whereNotNull('email')
            ->pluck('email')
            ->mapWithKeys(fn (string $email) => [mb_strtolower(trim($email)) => true])
            ->all();

        $rows = [];
        $seenInFile = [];
        $valid = 0;
        $invalid = 0;
        $duplicates = 0;

        foreach ($records as $index => $record) {
            $data = $this->mapRecord($record, $headers, $mapped);
            $errors = $this->validateRow($data);

            $companyKey = Company::normaliseName($data['company_name'] ?? null);
            $emailKey = mb_strtolower(trim((string) ($data['email'] ?? '')));

            $duplicateOf = [];

            if ($companyKey !== '' && isset($existingCompanies[$companyKey])) {
                $duplicateOf[] = 'company already in database';
            }

            if ($emailKey !== '' && isset($existingEmails[$emailKey])) {
                $duplicateOf[] = 'contact email already in database';
            }

            if ($emailKey !== '' && isset($seenInFile[$emailKey])) {
                $duplicateOf[] = 'email repeated earlier in this file (row '.$seenInFile[$emailKey].')';
            }

            if ($emailKey !== '') {
                $seenInFile[$emailKey] ??= $index + 2; // +2: 1-based, plus header row
            }

            $isDuplicate = $duplicateOf !== [];

            if ($errors !== []) {
                $invalid++;
            } elseif ($isDuplicate) {
                $duplicates++;
            } else {
                $valid++;
            }

            $rows[] = [
                'line' => $index + 2,
                'data' => $data,
                'errors' => $errors,
                'duplicate' => $isDuplicate,
                'duplicate_reasons' => $duplicateOf,
                'importable' => $errors === [],
            ];
        }

        return [
            'headers' => $headers,
            'mapped' => $mapped,
            'unmapped' => $unmapped,
            'rows' => $rows,
            'total' => count($rows),
            'valid' => $valid,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'truncated' => count($records) >= self::MAX_ROWS,
        ];
    }

    /**
     * Commit the file.
     *
     * Rows with validation errors are always skipped. Duplicate rows are skipped
     * unless $importDuplicates is true, in which case the existing company/contact
     * is reused and only the lead is added.
     *
     * @return array{imported: int, skipped: int, companies: int, contacts: int, leads: int, errors: list<string>}
     */
    public function import(UploadedFile $file, bool $importDuplicates = false): array
    {
        $analysis = $this->analyse($file);

        $imported = 0;
        $skipped = 0;
        $companiesCreated = 0;
        $contactsCreated = 0;
        $leadsCreated = 0;
        $errors = [];

        DB::transaction(function () use (
            $analysis, $importDuplicates,
            &$imported, &$skipped, &$companiesCreated, &$contactsCreated, &$leadsCreated, &$errors
        ): void {
            foreach ($analysis['rows'] as $row) {
                if (! $row['importable']) {
                    $skipped++;
                    $errors[] = "Row {$row['line']}: ".implode('; ', $row['errors']);

                    continue;
                }

                if ($row['duplicate'] && ! $importDuplicates) {
                    $skipped++;

                    continue;
                }

                $data = $row['data'];

                // Reuse an existing company by normalised name rather than
                // creating a near-identical second record.
                $company = $this->resolveCompany($data, $companiesCreated);
                $contact = $this->resolveContact($data, $company, $contactsCreated);

                Lead::create([
                    'company_id' => $company?->id,
                    'contact_id' => $contact?->id,
                    'lead_source' => $data['lead_source'] ?: LeadSource::Import->value,
                    'lead_status' => $data['lead_status'] ?: LeadStatus::New->value,
                    'priority' => $data['priority'] ?: Priority::Medium->value,
                    'assigned_to' => $data['assigned_to'] ?: null,
                    'notes' => $data['notes'] ?: null,
                ]);

                $leadsCreated++;
                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'companies' => $companiesCreated,
            'contacts' => $contactsCreated,
            'leads' => $leadsCreated,
            'errors' => $errors,
        ];
    }

    /**
     * Company columns from one row, ready for create() or a blank-fill update.
     *
     * Every field on the company form is represented, so an import can carry
     * the same information you would type by hand.
     *
     * @param  array<string, string>  $data
     * @return array<string, string|null>
     */
    private function companyAttributes(array $data): array
    {
        return [
            'name' => $data['company_name'],
            'website' => $data['website'] ?: null,
            'country' => $data['country'] ?: null,
            'state' => $data['state'] ?: null,
            'city' => $data['city'] ?: null,
            'industry' => $data['industry'] ?: null,
            'company_type' => $data['company_type'] ?: null,
            'description' => $data['company_description'] ?: null,

            // Multi-value fields are stored newline-separated, matching how the
            // form and Product::toLines() treat them. Excel writes CRLF inside a
            // quoted cell, and a stray carriage return would show up in the UI.
            'specialties' => $this->multiline($data['company_specialties']),
            'products_services' => $this->multiline($data['company_products']),

            'notes' => $data['company_notes'] ?: null,
        ];
    }

    /**
     * Normalise a multi-value cell to one value per line.
     *
     * Accepts newlines, semicolons or commas, because people write all three -
     * and Excel wraps any of them in CRLF.
     */
    private function multiline(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // Normalise line endings first, then split. No regex escapes to get
        // wrong, and it reads plainly.
        $normalised = str_replace(["\r\n", "\r", ';'], "\n", trim($value));

        $parts = array_values(array_filter(
            array_map(trim(...), explode("\n", $normalised)),
            fn (string $part) => $part !== '',
        ));

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * Normalised company name -> id, built once per import run.
     *
     * Without this, matching each row against existing companies would reload
     * the whole table per row.
     *
     * @var array<string, int>|null
     */
    private ?array $companyIndex = null;

    /** @param array<string, string> $data */
    private function resolveCompany(array $data, int &$created): ?Company
    {
        if (blank($data['company_name'])) {
            return null;
        }

        $this->companyIndex ??= Company::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name) => [Company::normaliseName($name) => $id])
            ->all();

        $key = Company::normaliseName($data['company_name']);

        if ($key !== '' && isset($this->companyIndex[$key])) {
            $existing = Company::find($this->companyIndex[$key]);

            // Enrich, never overwrite. A row that carries a description for a
            // company you already have should fill that gap - but a blank cell
            // must not wipe something you typed by hand.
            if ($existing !== null) {
                $fill = array_filter(
                    $this->companyAttributes($data),
                    fn ($value, $field) => filled($value) && blank($existing->{$field}),
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($fill !== []) {
                    $existing->update($fill);
                }
            }

            return $existing;
        }

        $created++;

        $company = Company::create($this->companyAttributes($data));

        // Keep the index warm so later rows naming the same company reuse it.
        if ($key !== '') {
            $this->companyIndex[$key] = $company->id;
        }

        return $company;
    }

    /** @param array<string, string> $data */
    private function resolveContact(array $data, ?Company $company, int &$created): ?Contact
    {
        if (blank($data['first_name']) && blank($data['email'])) {
            return null;
        }

        if (filled($data['email'])) {
            $existing = Contact::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();

            if ($existing) {
                return $existing;
            }
        }

        $created++;

        return Contact::create([
            'company_id' => $company?->id,
            // A row can carry only an email; fall back to its local part so the
            // contact still has a usable display name.
            'first_name' => $data['first_name'] ?: strtok($data['email'], '@'),
            'last_name' => $data['last_name'] ?: null,
            'job_title' => $data['job_title'] ?: null,
            'department' => $data['department'] ?: null,
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'linkedin_url' => $data['linkedin_url'] ?: null,
            // Contact-specific location when the file gives one, otherwise the
            // company's - a contact usually sits where the company does.
            'country' => $data['contact_country'] ?: ($data['country'] ?: null),
            'city' => $data['contact_city'] ?: ($data['city'] ?: null),
            'notes' => $data['contact_notes'] ?: null,
        ]);
    }

    /**
     * @param  list<string>  $record
     * @param  list<string>  $headers
     * @param  array<string, string|null>  $mapped
     * @return array<string, string>
     */
    private function mapRecord(array $record, array $headers, array $mapped): array
    {
        $fields = array_fill_keys(array_values(array_unique(array_values(self::COLUMN_MAP))), '');

        foreach ($headers as $position => $header) {
            $field = $mapped[$header] ?? null;

            if ($field === null) {
                continue;
            }

            $fields[$field] = trim((string) ($record[$position] ?? ''));
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $data
     * @return list<string>
     */
    private function validateRow(array $data): array
    {
        $validator = Validator::make($data, [
            'company_name' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'linkedin_url' => ['nullable', 'string', 'max:255'],
            'lead_status' => ['nullable', 'string', 'in:'.implode(',', LeadStatus::values())],
            'priority' => ['nullable', 'string', 'in:'.implode(',', Priority::values())],
        ], [
            'company_name.required_without' => 'Company Name is required when Email is blank.',
            'lead_status.in' => 'Lead Status must be one of: '.implode(', ', LeadStatus::values()),
            'priority.in' => 'Priority must be one of: '.implode(', ', Priority::values()),
        ]);

        // Blank optional cells must not trip "in:" rules.
        $validator->setData(array_map(fn (string $v) => $v === '' ? null : $v, $data));

        return array_values($validator->errors()->all());
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            return [[], []];
        }

        // Strip a UTF-8 BOM so the first header does not become "\u{FEFF}Company Name".
        $bom = fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = fgetcsv($handle, escape: '');
        $headers = is_array($headers) ? array_map(fn ($h) => trim((string) $h), $headers) : [];

        $records = [];

        while (count($records) < self::MAX_ROWS && ($record = fgetcsv($handle, escape: '')) !== false) {
            // Skip fully blank lines rather than reporting them as invalid rows.
            if ($record === [null] || implode('', array_map(fn ($v) => (string) $v, $record)) === '') {
                continue;
            }

            $records[] = array_map(fn ($v) => (string) $v, $record);
        }

        fclose($handle);

        return [$headers, $records];
    }

    private function normaliseHeader(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($header))) ?? '';
    }
}
