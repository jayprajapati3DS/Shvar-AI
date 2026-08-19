<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\Priority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lead_source')->nullable()->index();

            // Stored as plain strings rather than a DB enum: SQLite has no native
            // enum, and strings port to PostgreSQL unchanged. The PHP enum casts
            // on the model are what actually guard the values.
            $table->string('lead_status')->default(LeadStatus::New->value)->index();
            $table->string('priority')->default(Priority::Medium->value)->index();

            $table->string('assigned_to')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['lead_status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
