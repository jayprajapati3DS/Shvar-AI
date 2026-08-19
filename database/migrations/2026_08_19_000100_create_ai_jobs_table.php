<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI work that is running, or has run, in the background.
     *
     * Laravel already has a `jobs` table. This is not that. `jobs` is the
     * queue's own plumbing - a serialised closure that vanishes the moment it
     * succeeds - and it holds nothing you could show a person: no name, no
     * subject, no result, and no trace at all of the job that finished a minute
     * ago. This table is the part you can see. One row per thing you asked for,
     * from the moment you asked until you dismiss it.
     *
     * Which is why the two are kept apart rather than one being made to serve
     * both purposes. The queue may drop, retry or reschedule its own rows as it
     * sees fit; this row is the record of your request and outlives all of that.
     */
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->id();

            $table->string('type');
            $table->string('status')->default('Queued');

            // What this is about, written out at dispatch rather than joined at
            // read time. The tray has to render on every page load, and a lead
            // renamed - or deleted - after the job was queued should not change
            // what the job says it was doing.
            $table->string('label');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Everything the job needs to do the work. Ids and options only:
            // this is not a place to park a serialised model.
            $table->json('payload')->nullable();

            // Where the result lives, so "View" can go straight to it. Recorded
            // up front because the answer is known then, and a failed job should
            // still offer a way back to the page you started from.
            $table->string('result_url')->nullable();

            // What a synchronous run would have flashed at you, kept so the
            // outcome is still readable an hour later.
            $table->string('result_level')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Read and closed by the user. Kept rather than deleted - what the
            // model was asked to do, and how long it took, is the same kind of
            // history the AI log keeps.
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            // The tray's query: unfinished work, then anything finished the user
            // has not acknowledged yet.
            $table->index(['status', 'dismissed_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
