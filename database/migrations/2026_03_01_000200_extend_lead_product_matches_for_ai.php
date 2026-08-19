<?php

declare(strict_types=1);

use App\Enums\RecommendationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carries lead_product_matches from "a manual pick" to "an AI
     * recommendation with reasoning, awaiting human review".
     *
     * The important change is the LAST one: dropping unique(lead_id, product_id).
     *
     * That constraint was correct while matches were hand-made - you would not
     * attach the same product twice. It is wrong now: a lead can be analysed
     * repeatedly, each run is kept for history, and two runs will very often
     * recommend the same product. With the constraint in place the second
     * analysis would fail on insert, silently losing the newer reasoning.
     */
    public function up(): void
    {
        Schema::table('lead_product_matches', function (Blueprint $table): void {
            // Which analysis run produced this row. Null for manual picks.
            $table->foreignId('lead_analysis_id')
                ->nullable()
                ->after('product_id')
                ->constrained('lead_analyses')
                ->cascadeOnDelete();

            // Pitch order, distinct from confidence. See RecommendationPriority.
            $table->string('priority')->nullable()->after('recommendation_type')->index();

            // Human decision. Manual picks start Accepted - choosing one IS the
            // decision - while AI output starts Suggested and waits.
            $table->string('status')
                ->default(RecommendationStatus::Suggested->value)
                ->after('priority')
                ->index();

            // Quotes/facts drawn from the stored record that justify the match.
            // JSON because it is a list rendered as-is, never queried by element.
            $table->json('evidence')->nullable()->after('reason');

            $table->text('sales_angle')->nullable()->after('evidence');
            $table->text('suggested_use_case')->nullable()->after('sales_angle');

            // A capability inside a product rather than a separate catalogue
            // entry - e.g. "Knee Planning" within the 3dsurgical Platform.
            // Validated against the product's own key_features, so the AI
            // cannot invent a module that does not exist.
            $table->string('module')->nullable()->after('suggested_use_case');

            // Set when ConfidenceCalibrator lowered the model's score because
            // the stored record did not carry enough evidence to support it.
            $table->decimal('raw_confidence_score', 4, 3)->nullable()->after('confidence_score');

            $table->timestamp('reviewed_at')->nullable();
        });

        // SQLite cannot drop an index by column list, only by name, and the
        // name Laravel generated is deterministic.
        Schema::table('lead_product_matches', function (Blueprint $table): void {
            $table->dropUnique('lead_product_matches_lead_id_product_id_unique');
        });

        // Replaced with a plain index: still fast to look up, no longer unique.
        Schema::table('lead_product_matches', function (Blueprint $table): void {
            $table->index(['lead_id', 'product_id']);
            $table->index(['lead_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('lead_product_matches', function (Blueprint $table): void {
            $table->dropIndex(['lead_id', 'product_id']);
            $table->dropIndex(['lead_id', 'status']);
        });

        Schema::table('lead_product_matches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_analysis_id');
            $table->dropColumn([
                'priority',
                'status',
                'evidence',
                'sales_angle',
                'suggested_use_case',
                'module',
                'raw_confidence_score',
                'reviewed_at',
            ]);
        });

        // Restoring uniqueness can fail if history has accumulated duplicates,
        // which is expected - this rollback is only safe on a fresh install.
        Schema::table('lead_product_matches', function (Blueprint $table): void {
            $table->unique(['lead_id', 'product_id']);
        });
    }
};
