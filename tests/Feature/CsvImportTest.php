<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    /** Builds an in-memory CSV upload from rows of text. */
    private function csv(string $contents, string $name = 'leads.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function validCsv(): string
    {
        return <<<'CSV'
        Company Name,Website,Country,City,Contact First Name,Contact Last Name,Job Title,Email,Industry,Lead Source,Lead Status,Priority
        Acme Medical,acme.example,India,Ahmedabad,Asha,Patel,Head of R&D,asha@acme.example,Medical Devices,LinkedIn,Qualified,High
        Beta Implants,beta.example,Germany,Berlin,Jonas,Weber,CTO,jonas@beta.example,Implants,Referral,New,Medium
        CSV;
    }

    public function test_the_import_page_renders_with_no_preview(): void
    {
        $this->get(route('import.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Import/Index')
                ->where('preview', null)
                ->has('template.columns'));
    }

    public function test_preview_reports_rows_without_writing_anything(): void
    {
        $this->post(route('import.preview'), ['file' => $this->csv($this->validCsv())])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Import/Index')
                ->where('preview.total', 2)
                ->where('preview.valid', 2)
                ->where('preview.invalid', 0)
                ->where('preview.duplicates', 0));

        // Nothing is persisted by a preview.
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_import_creates_companies_and_leads(): void
    {
        $this->post(route('import.store'), ['file' => $this->csv($this->validCsv())])
            ->assertRedirect(route('leads.index'));

        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('leads', 2);

        $this->assertDatabaseHas('companies', [
            'name' => 'Acme Medical',
            'country' => 'India',
            'city' => 'Ahmedabad',
            'industry' => 'Medical Devices',
        ]);

        $lead = Lead::whereHas('company', fn ($q) => $q->where('name', 'Acme Medical'))->sole();

        $this->assertSame('Qualified', $lead->lead_status->value);
        $this->assertSame('High', $lead->priority->value);
        $this->assertSame('LinkedIn', $lead->lead_source);
        $this->assertSame('Asha Patel', $lead->full_name);
    }

    public function test_headers_are_matched_case_and_separator_insensitively(): void
    {
        $csv = <<<'CSV'
        company_name,EMAIL,Job Title
        Loose Headers Ltd,loose@example.com,Engineer
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $this->assertDatabaseHas('companies', ['name' => 'Loose Headers Ltd']);
        $this->assertDatabaseHas('leads', ['email' => 'loose@example.com', 'job_title' => 'Engineer']);
    }

    public function test_unrecognised_columns_are_reported_and_ignored(): void
    {
        $csv = <<<'CSV'
        Company Name,Favourite Colour
        Odd Columns Co,Blue
        CSV;

        $this->post(route('import.preview'), ['file' => $this->csv($csv)])
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.unmapped', ['Favourite Colour'])
                ->where('preview.valid', 1));
    }

    public function test_blank_status_and_priority_fall_back_to_defaults(): void
    {
        $csv = <<<'CSV'
        Company Name,Email,Lead Status,Priority
        Defaults Co,defaults@example.com,,
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $lead = Lead::sole();

        $this->assertSame('New', $lead->lead_status->value);
        $this->assertSame('Medium', $lead->priority->value);
    }

    public function test_invalid_rows_are_flagged_and_never_imported(): void
    {
        $csv = <<<'CSV'
        Company Name,Email,Lead Status
        Good Co,good@example.com,New
        Bad Status Co,bad@example.com,Not A Real Status
        ,,New
        Bad Email Co,definitely-not-an-email,New
        CSV;

        $this->post(route('import.preview'), ['file' => $this->csv($csv)])
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.total', 4)
                ->where('preview.valid', 1)
                ->where('preview.invalid', 3));

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        // Only the one valid row lands.
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('companies', ['name' => 'Good Co']);
        $this->assertDatabaseMissing('companies', ['name' => 'Bad Status Co']);
    }

    public function test_existing_companies_and_emails_are_detected_as_duplicates(): void
    {
        Company::factory()->create(['name' => 'Acme Medical, Inc.']);
        Lead::factory()->create(['email' => 'jonas@beta.example']);

        $this->post(route('import.preview'), ['file' => $this->csv($this->validCsv())])
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.total', 2)
                ->where('preview.valid', 0)
                // Row 1 matches the normalised company name, row 2 the email.
                ->where('preview.duplicates', 2));
    }

    public function test_a_repeated_email_within_the_file_is_flagged(): void
    {
        $csv = <<<'CSV'
        Company Name,Email
        First Co,same@example.com
        Second Co,same@example.com
        CSV;

        $this->post(route('import.preview'), ['file' => $this->csv($csv)])
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.valid', 1)
                ->where('preview.duplicates', 1));
    }

    public function test_duplicates_are_skipped_by_default(): void
    {
        Company::factory()->create(['name' => 'Acme Medical']);

        $this->post(route('import.store'), ['file' => $this->csv($this->validCsv())])
            ->assertRedirect();

        // Only Beta Implants imports; the Acme row is skipped.
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('companies', ['name' => 'Beta Implants']);
    }

    public function test_duplicates_reuse_the_existing_company_when_opted_in(): void
    {
        $existing = Company::factory()->create(['name' => 'Acme Medical, Inc.']);

        $this->post(route('import.store'), [
            'file' => $this->csv($this->validCsv()),
            'import_duplicates' => true,
        ])->assertRedirect();

        // No second Acme record: the normalised name matched the existing one.
        $this->assertSame(2, Company::count());
        $this->assertDatabaseHas('leads', ['company_id' => $existing->id]);
    }

    public function test_a_company_named_twice_in_one_file_is_only_created_once(): void
    {
        $csv = <<<'CSV'
        Company Name,Contact First Name,Email
        Repeat Corp,Alice,alice@repeat.example
        Repeat Corp,Bob,bob@repeat.example
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('leads', 2);
    }

    public function test_a_row_with_only_an_email_derives_a_contact_name(): void
    {
        $csv = <<<'CSV'
        Company Name,Email
        Nameless Co,someone@nameless.example
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $this->assertDatabaseHas('leads', ['first_name' => 'someone']);
    }

    public function test_blank_lines_are_skipped_rather_than_reported_as_errors(): void
    {
        $csv = "Company Name,Email\nReal Co,real@example.com\n\n\n";

        $this->post(route('import.preview'), ['file' => $this->csv($csv)])
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.total', 1)
                ->where('preview.valid', 1));
    }

    public function test_a_utf8_bom_does_not_break_the_first_header(): void
    {
        $csv = "\xEF\xBB\xBFCompany Name,Email\nBom Co,bom@example.com\n";

        $this->post(route('import.preview'), ['file' => $this->csv($csv)])
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.valid', 1)
                ->where('preview.unmapped', []));
    }

    public function test_a_non_csv_upload_is_rejected(): void
    {
        $this->post(route('import.store'), [
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    }

    public function test_every_company_form_field_can_be_imported(): void
    {
        // The gap this closes: the template used to omit description,
        // specialties, products/services and company notes, so a CSV could not
        // carry what the form could.
        $csv = <<<'CSV'
        Company Name,Website,Industry,Company Type,Country,State,City,Description,Specialties,Products Services,Company Notes
        Northfield Ortho,northfield.example,Medical Devices,PSI Manufacturer,United States,Minnesota,Minneapolis,Designs patient-specific knee instrumentation.,Knee;Hip,Cutting guides,Met at a trade show
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $company = Company::sole();

        $this->assertSame('Northfield Ortho', $company->name);
        $this->assertSame('Medical Devices', $company->industry);
        $this->assertSame('PSI Manufacturer', $company->company_type);
        $this->assertSame('Minnesota', $company->state);
        $this->assertSame('Designs patient-specific knee instrumentation.', $company->description);
        // Semicolons, commas and CRLF in a multi-value cell all normalise to
        // one value per line, matching how the form stores them.
        $this->assertSame("Knee\nHip", $company->specialties);
        $this->assertSame('Cutting guides', $company->products_services);
        $this->assertSame('Met at a trade show', $company->notes);
    }

    public function test_person_and_lead_specific_columns_are_imported(): void
    {
        $csv = <<<'CSV'
        Company Name,Country,City,Contact First Name,Email,Contact Country,Contact City,Contact Notes,Assigned To,Notes
        Acme Medical,Germany,Berlin,Asha,asha@acme.example,India,Chennai,Prefers email,Jay Prajapati,Wants a demo
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $person = Lead::sole();
        $lead = Lead::sole();

        // Contact location overrides the company's when the file gives one.
        $this->assertSame('India', $person->country);
        $this->assertSame('Chennai', $person->city);
        $this->assertStringContainsString('Prefers email', (string) $person->notes);

        $this->assertSame('Jay Prajapati', $lead->assigned_to);
        // The two notes columns merge, both labelled, so neither is lost.
        $this->assertStringContainsString('Wants a demo', (string) $lead->notes);
        $this->assertStringContainsString('Prefers email', (string) $lead->notes);
    }

    public function test_a_person_inherits_the_company_location_when_none_is_given(): void
    {
        $csv = <<<'CSV'
        Company Name,Country,City,Contact First Name,Email
        Acme Medical,Germany,Berlin,Asha,asha@acme.example
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $person = Lead::sole();

        $this->assertSame('Germany', $person->country);
        $this->assertSame('Berlin', $person->city);
    }

    public function test_bare_notes_still_means_the_lead_note(): void
    {
        // Backwards compatibility: files written against the old template must
        // keep importing exactly as before.
        $csv = <<<'CSV'
        Company Name,Email,Notes
        Legacy Co,legacy@example.com,A lead note
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $this->assertSame('A lead note', Lead::sole()->notes);
        $this->assertNull(Company::sole()->notes);
    }

    public function test_importing_fills_blanks_on_an_existing_company_without_overwriting(): void
    {
        $existing = Company::factory()->create([
            'name' => 'Acme Medical',
            'industry' => 'Hand-typed industry',
            'description' => null,
        ]);

        $csv = <<<'CSV'
        Company Name,Industry,Description,Email
        Acme Medical,CSV industry,A description from the file,someone@acme.example
        CSV;

        $this->post(route('import.store'), [
            'file' => $this->csv($csv),
            'import_duplicates' => true,
        ])->assertRedirect();

        $existing->refresh();

        // The blank was filled...
        $this->assertSame('A description from the file', $existing->description);
        // ...but what you typed by hand was left alone.
        $this->assertSame('Hand-typed industry', $existing->industry);
    }

    public function test_alternative_header_spellings_are_accepted(): void
    {
        // "First Name" is needed for a contact to exist at all - a row with a
        // job title and nobody to attach it to creates no contact, by design.
        $csv = <<<'CSV'
        Company,URL,Type,About,First Name,Role,Owner
        Acme Medical,acme.example,Hospital,They do surgery,Asha,Head of R&D,Jay
        CSV;

        $this->post(route('import.store'), ['file' => $this->csv($csv)])->assertRedirect();

        $company = Company::sole();

        $this->assertSame('Acme Medical', $company->name);
        $this->assertSame('Hospital', $company->company_type);
        $this->assertSame('They do surgery', $company->description);
        $this->assertSame('Head of R&D', Lead::sole()->job_title);
        $this->assertSame('Jay', Lead::sole()->assigned_to);
    }

    public function test_the_template_covers_every_importable_field(): void
    {
        $response = $this->get(route('import.template'));
        $header = strtolower(explode('
', $response->streamedContent())[0]);

        // If a field exists on the form it must be in the template, or a user
        // cannot import what they can type.
        foreach ([
            'description', 'specialties', 'products services', 'company notes',
            'contact country', 'contact city', 'contact notes', 'assigned to',
        ] as $column) {
            $this->assertStringContainsString($column, $header, "Template is missing [{$column}].");
        }
    }

    public function test_the_template_includes_a_usable_example_row(): void
    {
        $content = $this->get(route('import.template'))->streamedContent();
        $lines = array_filter(explode('
', $content));

        $this->assertGreaterThan(1, count($lines), 'Template should carry an example row.');
        // On a .example domain, so it cannot be mistaken for a real lead.
        $this->assertStringContainsString('.example', $content);
    }

    public function test_the_template_download_contains_the_expected_headers(): void
    {
        $response = $this->get(route('import.template'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));

        $this->assertStringContainsString('Company Name', $response->streamedContent());
        $this->assertStringContainsString('Lead Status', $response->streamedContent());
    }
}
