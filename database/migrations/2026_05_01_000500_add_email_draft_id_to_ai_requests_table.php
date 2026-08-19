<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an AI request to the generation run it belongs to, mirroring the
     * lead_id and company_id columns added in Phases 3 and 4.
     *
     * nullOnDelete: deleting a generation should not erase the record that AI
     * ran, only which run it concerned.
     */
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->foreignId('email_generation_id')
                ->nullable()
                ->after('company_id')
                ->constrained('email_generations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('email_generation_id');
        });
    }
};
