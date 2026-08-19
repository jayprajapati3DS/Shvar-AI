<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lead IS a person now.
     *
     * Contact and Lead were nearly 1:1 and neither was complete on its own: a
     * contact had a name and no pipeline, a lead had a pipeline and no name.
     * In practice that meant entering the same person twice - the live data had
     * four contacts and four leads with exactly one link between them.
     *
     * So the person's details move onto the lead, and contacts stop being a
     * separate thing you maintain. The leads list becomes the people list.
     *
     * The company is what you are trying to win; leads are the people you go
     * through to win it. Several leads per company is the normal case, not an
     * edge case.
     *
     * DATA MIGRATION, in order:
     *
     *   1. Every lead that already has a contact absorbs that contact's fields.
     *   2. Every contact WITHOUT a lead becomes a new lead, status New - it is a
     *      person at a company you have not started working yet, which is
     *      exactly what a new lead is.
     *   3. Everything that pointed at a contact is repointed at the lead.
     *   4. contacts is dropped.
     */
    public function up(): void
    {
        $this->addPersonFieldsToLeads();
        $this->absorbContactsIntoExistingLeads();
        $this->promoteOrphanContactsToLeads();
        $this->repointForeignKeys();
        $this->dropContacts();
    }

    private function addPersonFieldsToLeads(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('contact_id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('job_title')->nullable()->after('last_name');
            $table->string('department')->nullable()->after('job_title');
            $table->string('email')->nullable()->after('department')->index();
            $table->string('phone')->nullable()->after('email');
            $table->string('linkedin_url')->nullable()->after('phone');

            // The person's own location, which is not always the company's.
            $table->string('country')->nullable()->after('linkedin_url');
            $table->string('city')->nullable()->after('country');
        });
    }

    /** Leads that already had a contact take its details. */
    private function absorbContactsIntoExistingLeads(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

        DB::table('leads')
            ->whereNotNull('contact_id')
            ->orderBy('id')
            ->each(function (object $lead): void {
                $contact = DB::table('contacts')->find($lead->contact_id);

                if ($contact === null) {
                    return;
                }

                DB::table('leads')->where('id', $lead->id)->update([
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'job_title' => $contact->job_title,
                    'department' => $contact->department,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'linkedin_url' => $contact->linkedin_url,
                    'country' => $contact->country,
                    'city' => $contact->city,

                    // Two sets of notes become one, both labelled so neither
                    // silently disappears into the other.
                    'notes' => $this->mergeNotes($lead->notes, $contact->notes),

                    // Fall back to the contact's company when the lead had none.
                    'company_id' => $lead->company_id ?? $contact->company_id,
                ]);
            });
    }

    /**
     * A contact nobody had made a lead from becomes one.
     *
     * That is not an invention: a person at a company you have not started
     * working is precisely a new lead, and leaving them behind would lose real
     * data on the way through.
     */
    private function promoteOrphanContactsToLeads(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

        $linked = DB::table('leads')->whereNotNull('contact_id')->pluck('contact_id')->all();

        DB::table('contacts')
            ->when($linked !== [], fn ($q) => $q->whereNotIn('id', $linked))
            ->orderBy('id')
            ->each(function (object $contact): void {
                $id = DB::table('leads')->insertGetId([
                    'company_id' => $contact->company_id,
                    'contact_id' => $contact->id,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'job_title' => $contact->job_title,
                    'department' => $contact->department,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'linkedin_url' => $contact->linkedin_url,
                    'country' => $contact->country,
                    'city' => $contact->city,
                    'notes' => $contact->notes,
                    'lead_status' => 'New',
                    'priority' => 'Medium',
                    'created_at' => $contact->created_at,
                    'updated_at' => $contact->updated_at,
                ]);

                // So the repointing below finds this lead for the contact.
                DB::table('leads')->where('id', $id)->update(['contact_id' => $contact->id]);
            });
    }

    /**
     * Everything that pointed at a contact now points at the lead.
     *
     * Done by looking up the lead that absorbed each contact, so an email draft
     * addressed to someone still finds the right person after the merge.
     */
    private function repointForeignKeys(): void
    {
        $leadForContact = DB::table('leads')
            ->whereNotNull('contact_id')
            ->pluck('id', 'contact_id')
            ->all();

        foreach ([
            'email_generations' => 'contact_id',
            'email_drafts' => 'contact_id',
            'email_replies' => 'contact_id',
            'follow_up_tasks' => 'contact_id',
        ] as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            // These tables already carry lead_id. Fill it in from the contact
            // where it is missing, then the contact column can go.
            foreach ($leadForContact as $contactId => $leadId) {
                DB::table($table)
                    ->where($column, $contactId)
                    ->whereNull('lead_id')
                    ->update(['lead_id' => $leadId]);
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropConstrainedForeignId($column);
            });
        }

        // Activities recorded against a contact move to their lead. The
        // timeline is the point of that table; losing entries would be worse
        // than a slightly odd subject reference.
        if (Schema::hasTable('activities')) {
            foreach ($leadForContact as $contactId => $leadId) {
                DB::table('activities')
                    ->where('subject_type', 'App\\Models\\Contact')
                    ->where('subject_id', $contactId)
                    ->update([
                        'subject_type' => 'App\\Models\\Lead',
                        'subject_id' => $leadId,
                    ]);
            }
        }
    }

    private function dropContacts(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contact_id');
        });

        Schema::dropIfExists('contacts');
    }

    private function mergeNotes(?string $leadNotes, ?string $contactNotes): ?string
    {
        $lead = trim((string) $leadNotes);
        $contact = trim((string) $contactNotes);

        if ($lead === '') {
            return $contact === '' ? null : $contact;
        }

        if ($contact === '') {
            return $lead;
        }

        return $lead."\n\nFrom the contact record:\n".$contact;
    }

    /**
     * Not reversible.
     *
     * Splitting a lead back into a lead and a contact would have to guess which
     * notes belonged to which and which leads were once contacts. The backup
     * taken before this ran is the way back.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Merging contacts into leads cannot be undone. Restore the database backup taken '
            .'before the migration ran (database/database.sqlite.bak-before-lead-merge-*).'
        );
    }
};
