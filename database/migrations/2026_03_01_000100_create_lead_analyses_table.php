<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One AI analysis run against one lead.
     *
     * A lead can be analysed many times; every run is kept so the reasoning
     * behind an old recommendation stays inspectable. Nothing here is ever
     * overwritten - a re-analysis creates a new row.
     *
     * Analysis-level output lives here; the per-product output lives on
     * lead_product_matches, which points back at the run that produced it.
     */
    public function up(): void
    {
        Schema::create('lead_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // Which model produced it, so an old analysis stays interpretable
            // after the configured model changes.
            $table->string('provider');
            $table->string('model');

            // The AI's reading of the company. Free text, shown to the user.
            $table->text('company_summary')->nullable();
            $table->string('company_type')->nullable();
            $table->text('business_opportunity')->nullable();

            // Lists, stored as JSON because they are displayed as-is and never
            // queried by element.
            $table->json('products_to_avoid')->nullable();
            $table->json('missing_information')->nullable();
            $table->text('recommended_next_action')->nullable();

            // What RecommendationValidator discarded from the model's output -
            // an invented product, a module the product does not have, a
            // duplicate. Shown to the user so the filtering is visible rather
            // than silent.
            $table->json('warnings')->nullable();

            // Confidence of the top pick, denormalised for the history list so
            // it does not need a join to render.
            $table->decimal('primary_confidence', 4, 3)->nullable();
            $table->foreignId('primary_product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->unsignedInteger('execution_time_ms')->nullable();

            // Link to the Phase 2 AI request log entry, so the exact prompt and
            // raw response behind this analysis can be pulled up.
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();

            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_analyses');
    }
};
