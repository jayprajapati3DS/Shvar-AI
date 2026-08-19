<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an AI request back to the lead it was about.
     *
     * Nullable because most requests have no lead - the Playground, the
     * connection test. nullOnDelete rather than cascade: deleting a lead should
     * not erase the record that AI was run, only which lead it concerned.
     */
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->foreignId('lead_id')
                ->nullable()
                ->after('request_type')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
