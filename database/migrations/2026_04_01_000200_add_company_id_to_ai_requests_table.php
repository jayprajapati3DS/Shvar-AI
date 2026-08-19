<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an AI request to the company it was about, mirroring lead_id.
     *
     * nullOnDelete: removing a company should not erase the record that AI ran,
     * only which company it concerned.
     */
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('lead_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
