<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company carries the pipeline now.
     *
     * The lead status says where one PERSON has got to. This says where the
     * ACCOUNT has got to, which is the thing actually being won or lost. You
     * may work five people at a firm and win the firm once.
     *
     * Both statuses stay, because they answer different questions: "has Dana
     * replied yet" and "are we going to land Craniofax" are not the same
     * question and collapsing them would lose one of them.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('status')->default('Prospect')->after('company_type')->index();

            // Stamped by the transition, so "when did we win them" is a fact
            // rather than something inferred from an activity entry.
            $table->timestamp('won_at')->nullable()->after('status');
            $table->timestamp('lost_at')->nullable()->after('won_at');

            // Why it was lost. The most useful field on a lost account and the
            // one nobody records unless asked for it plainly.
            $table->string('outcome_reason')->nullable()->after('lost_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['status', 'won_at', 'lost_at', 'outcome_reason']);
        });
    }
};
