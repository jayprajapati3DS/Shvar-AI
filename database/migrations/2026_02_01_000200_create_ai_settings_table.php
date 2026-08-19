<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Locally saved AI settings, as key/value rows.
     *
     * Only the safe knobs live here - model, temperature, timeout, max tokens,
     * system prompt. The ENDPOINT DELIBERATELY DOES NOT: it stays in
     * config/ai.php (from .env) so it cannot be repointed at a remote host from
     * the browser. See App\Services\AI\AiSettings.
     *
     * Key/value rather than a single-row table so Phase 3 can add settings
     * without a migration.
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
