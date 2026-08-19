<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One email the user could send: a single variant of a single generation.
     *
     * The subject/body pair is the LIVE text, edited freely. ai_subject and
     * ai_body hold what the model actually wrote and are never updated after
     * insert - so "what did the AI produce before I rewrote it" always has an
     * answer, and an edited draft can be compared against its origin.
     *
     * status starts at Draft and reaches Sent only through Approved. approved_at
     * and sent_at are set by those transitions and by nothing else.
     *
     * PRIVACY: recipient_email is a copy of the contact's address at generation
     * time, kept so a sent record stays truthful if the contact is later edited
     * or deleted. It never leaves this machine - LocalTestEmailService only
     * writes to the local log.
     */
    public function up(): void
    {
        Schema::create('email_drafts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('email_generation_id')->nullable()
                ->constrained('email_generations')->cascadeOnDelete();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_product_match_id')->nullable()
                ->constrained('lead_product_matches')->nullOnDelete();

            $table->string('variant');                       // App\Enums\EmailVariant
            $table->string('status')->index();               // App\Enums\EmailDraftStatus

            $table->string('subject');
            $table->text('body');

            // Immutable record of the model's own words.
            $table->string('ai_subject')->nullable();
            $table->text('ai_body')->nullable();

            // Snapshot of who it was for, independent of the contact record.
            $table->string('recipient_email')->nullable();
            $table->string('recipient_name')->nullable();

            $table->json('personalization_points')->nullable();

            // The rule-based checks, recomputed on every save so the panel never
            // shows a verdict about text that has since changed.
            $table->json('quality')->nullable();
            $table->unsignedInteger('word_count')->nullable();

            $table->string('ai_model')->nullable();
            $table->foreignId('ai_request_id')->nullable()
                ->constrained('ai_requests')->nullOnDelete();

            // Free text rather than a users FK: this is a single-user local
            // application with no auth layer, and the signature name is what
            // makes a record meaningful here.
            $table->string('created_by')->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // 'simulated' for everything Phase 4 can do. A real transport would
            // set its own value, so a simulated send can never be mistaken for
            // a delivered one.
            $table->string('delivery_mode')->nullable();
            $table->text('delivery_error')->nullable();

            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_drafts');
    }
};
