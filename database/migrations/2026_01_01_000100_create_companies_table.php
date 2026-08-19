<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->string('website')->nullable();
            $table->string('country')->nullable()->index();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('industry')->nullable()->index();
            $table->string('company_type')->nullable()->index();
            $table->text('description')->nullable();
            $table->text('specialties')->nullable();
            $table->text('products_services')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Duplicate detection during CSV import keys on the normalised name.
            $table->index(['name', 'country']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
