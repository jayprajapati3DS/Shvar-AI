<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Something to do about a lead, on a date.
     *
     * A task may be suggested by the model after reading a reply, or written by
     * hand. `source` records which, and it is not cosmetic: a suggestion is
     * something to check, and knowing which tasks came from a machine is the
     * difference between a to-do list you trust and one you stop reading.
     *
     * Nothing here acts on its own. A task is a reminder; it never sends,
     * schedules or generates anything by itself.
     */
    public function up(): void
    {
        Schema::create('follow_up_tasks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            // The reply that prompted it, when there was one.
            $table->foreignId('email_reply_id')->nullable()
                ->constrained('email_replies')->nullOnDelete();

            $table->string('title');
            $table->text('notes')->nullable();

            $table->string('status')->default('Open')->index();   // App\Enums\FollowUpStatus
            $table->string('priority')->nullable();               // App\Enums\Priority
            $table->string('source')->default('manual');          // 'manual' | 'ai'

            $table->date('due_on')->nullable()->index();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_tasks');
    }
};
