<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every state a draft's text has been in.
     *
     * Version 1 is always the AI's own output, written at generation time.
     * Each save appends a row rather than replacing one, so an edit can be read
     * back and compared, and the original survives however many rewrites later.
     *
     * Insert-only by design: nothing in the application updates or deletes a row
     * here except the cascade when its draft is deleted.
     */
    public function up(): void
    {
        Schema::create('email_draft_versions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('email_draft_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version');

            $table->string('subject');
            $table->text('body');

            // 'ai' or 'user' - who wrote this state.
            $table->string('source');

            $table->unsignedInteger('word_count')->nullable();

            $table->timestamps();

            $table->unique(['email_draft_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_draft_versions');
    }
};
