<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One website-research run against one company.
     *
     * Mirrors lead_analyses: additive history, never overwritten, so you can
     * see what a site said in March even after it was redesigned.
     *
     * Findings are stored as JSON rather than applied to the company. Nothing
     * touches the company record until you accept a field.
     */
    public function up(): void
    {
        Schema::create('company_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // What was actually fetched, which may differ from what was typed
            // after redirects - worth recording for an audit trail.
            $table->string('requested_url');
            $table->json('fetched_urls')->nullable();

            $table->string('provider');
            $table->string('model');

            // [{field, label, value, evidence, confidence, is_list}, ...]
            // Each carries a verbatim quote verified against the fetched text.
            $table->json('findings')->nullable();

            // Fields the page did not establish.
            $table->json('not_found')->nullable();

            // What CompanyResearchValidator discarded, and why.
            $table->json('warnings')->nullable();

            $table->text('page_summary')->nullable();

            // Which fields the user actually applied, and when. Null while the
            // analysis is still awaiting review.
            $table->json('applied_fields')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->unsignedInteger('fetch_time_ms')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->unsignedInteger('source_chars')->nullable();

            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_analyses');
    }
};
