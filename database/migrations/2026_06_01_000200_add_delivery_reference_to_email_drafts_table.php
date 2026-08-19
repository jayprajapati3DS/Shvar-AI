<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transport-specific handle for a message that was handed off.
     *
     * Holds the Outlook EntryID of the compose window this draft became, so a
     * draft sitting in Queued can later be reconciled: once the user actually
     * presses Send, the item reports a SentOn timestamp and the draft moves to
     * Sent. Without this there is no way to tell "opened and sent" from "opened
     * and abandoned".
     */
    public function up(): void
    {
        Schema::table('email_drafts', function (Blueprint $table): void {
            $table->string('delivery_reference')->nullable()->after('delivery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('email_drafts', function (Blueprint $table): void {
            $table->dropColumn('delivery_reference');
        });
    }
};
