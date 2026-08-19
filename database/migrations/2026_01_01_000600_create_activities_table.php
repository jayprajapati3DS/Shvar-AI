<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the activity timeline on lead / company / contact detail pages.
     *
     * Polymorphic so a single timeline component works for every entity, and so
     * Phase 2 can log emails and follow-ups against whatever they belong to
     * without another table.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subject');            // subject_type + subject_id
            $table->string('type')->index();      // App\Enums\ActivityType
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
