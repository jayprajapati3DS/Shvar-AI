<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which accepted recommendations one email was written from.
     *
     * email_generations already carries product_id and lead_product_match_id.
     * Those stay, and now mean THE PRIMARY - the product the email leads with,
     * the one the drafts list and the filters show. This table holds the full
     * set, primary included, so an email can pitch more than one product.
     *
     * Keeping the primary denormalised on the generation is deliberate: every
     * existing query, resource and filter keeps working unchanged, and "what is
     * this email mainly about" stays a single column rather than a join with a
     * where-clause.
     */
    public function up(): void
    {
        Schema::create('email_generation_recommendations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('email_generation_id')
                ->constrained('email_generations')
                ->cascadeOnDelete();

            $table->foreignId('lead_product_match_id')
                ->constrained('lead_product_matches')
                ->cascadeOnDelete();

            // Exactly one row per generation has this set.
            $table->boolean('is_primary')->default(false);

            // The order the user chose, which is the order the model is told to
            // weigh them in.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['email_generation_id', 'lead_product_match_id'], 'email_gen_rec_unique');
            $table->index(['email_generation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_generation_recommendations');
    }
};
