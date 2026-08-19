<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per AI email generation run.
     *
     * Mirrors lead_analyses from Phase 3: the run is recorded separately from
     * what it produced, so regenerating adds history instead of overwriting it.
     * Each run yields three email_drafts (one per variant) that all point here.
     *
     * The AI's own accounting - which facts it personalised on, which product
     * claims it used, what it noticed was missing - lives here rather than on
     * each draft, because it describes the run, not the wording of one variant.
     */
    public function up(): void
    {
        Schema::create('email_generations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // Kept nullable and nullOnDelete: losing the contact or the product
            // should not erase the record that an email was generated.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // The accepted Phase 3 recommendation this email was written from.
            $table->foreignId('lead_product_match_id')->nullable()
                ->constrained('lead_product_matches')->nullOnDelete();

            $table->string('provider');
            $table->string('model');

            // The settings in force for this run, stored rather than looked up
            // later - changing your default tone must not rewrite history.
            $table->string('tone');
            $table->string('length');
            $table->text('extra_instructions')->nullable();

            // The AI's self-report. claims_used is checked against the product
            // record by EmailValidator before any of this is stored.
            $table->json('personalization_points')->nullable();
            $table->json('claims_used')->nullable();
            $table->json('missing_information')->nullable();

            // What the validator rejected or repaired, so the UI can be honest
            // about the output having been filtered.
            $table->json('warnings')->nullable();

            $table->unsignedInteger('execution_time_ms')->nullable();

            // The Phase 2 log row for this call.
            $table->foreignId('ai_request_id')->nullable()
                ->constrained('ai_requests')->nullOnDelete();

            // Set when this run replaced an earlier one via "Regenerate".
            $table->foreignId('regenerated_from_id')->nullable()
                ->constrained('email_generations')->nullOnDelete();

            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_generations');
    }
};
