<?php

declare(strict_types=1);

use App\Enums\RecommendationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_product_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('recommendation_type')->default(RecommendationType::Manual->value)->index();

            // 0.00 - 1.00. Null for manual matches; Phase 2 AI matching fills this in.
            $table->decimal('confidence_score', 4, 3)->nullable();

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // A product can only be attached to a given lead once.
            $table->unique(['lead_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_product_matches');
    }
};
