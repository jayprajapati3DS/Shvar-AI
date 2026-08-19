<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('category')->nullable()->index();
            $table->text('short_description')->nullable();
            $table->text('detailed_description')->nullable();
            $table->text('target_customer')->nullable();
            $table->text('target_specialty')->nullable();
            $table->text('key_features')->nullable();
            $table->text('value_proposition')->nullable();
            $table->text('sales_notes')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
