<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message received in Outlook from someone in the CRM.
     *
     * PRIVACY, and this table is where it matters most. Everything before this
     * point held data you typed or the model wrote. This holds mail other people
     * sent you.
     *
     * So the scope is narrow and enforced upstream in OutlookMailboxSync: only
     * messages whose sender address matches a Contact record are ever read, and
     * nothing else in the mailbox is opened, stored or shown. The body is kept
     * because a reply is useless without it - you cannot judge "are they
     * interested" from a subject line - but it never leaves this machine, and
     * the AI that reads it is the same local Ollama as everything else.
     */
    public function up(): void
    {
        Schema::create('email_replies', function (Blueprint $table): void {
            $table->id();

            // Outlook's own identifier. Unique, which is what makes the sync
            // idempotent: running it twice imports nothing twice.
            $table->string('outlook_entry_id')->unique();
            $table->string('conversation_id')->nullable()->index();

            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            // The draft this appears to answer, when one can be matched.
            $table->foreignId('email_draft_id')->nullable()
                ->constrained('email_drafts')->nullOnDelete();

            $table->string('from_address')->index();
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('received_at')->index();

            // What the local model made of it. Null until classified, and null
            // forever if you switch classification off.
            $table->string('classification')->nullable()->index();
            $table->text('summary')->nullable();
            $table->float('sentiment')->nullable();
            $table->json('signals')->nullable();
            $table->foreignId('ai_request_id')->nullable()
                ->constrained('ai_requests')->nullOnDelete();

            // Whether you have dealt with it.
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['lead_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_replies');
    }
};
