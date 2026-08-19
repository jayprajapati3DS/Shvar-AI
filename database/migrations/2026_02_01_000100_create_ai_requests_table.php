<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The local AI request log.
     *
     * PRIVACY: this table stays on this machine. It is never transmitted, and
     * nothing reads it except the Settings -> AI Logs page. It exists so you can
     * see exactly what was sent to the local model and what came back.
     */
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();

            $table->string('provider')->index();          // 'ollama'
            $table->string('model')->index();
            $table->string('request_type')->index();      // App\Enums\AiRequestType
            $table->string('status')->index();            // App\Enums\AiRequestStatus

            $table->text('prompt')->nullable();
            $table->text('response')->nullable();

            $table->unsignedInteger('execution_time_ms')->nullable()->index();

            // Technical detail for diagnosis. The UI shows the user-safe message
            // from the exception instead of this.
            $table->text('error_message')->nullable();

            // Extras that make the log genuinely useful without another table:
            // token counts when the runtime reports them, whether structured
            // output was requested, and the prompt template that produced it.
            $table->boolean('structured')->default(false);
            $table->string('template')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('response_tokens')->nullable();
            $table->float('temperature')->nullable();

            $table->timestamps();

            // The log page lists newest-first, optionally filtered by status/type.
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
