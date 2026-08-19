<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local key/value store for the signature block and writing preferences.
     *
     * Deliberately separate from ai_settings. That table is a security boundary
     * whose whitelist exists to stop the browser repointing AI traffic; mixing
     * signature fields into it would blur what that whitelist is protecting.
     * Same shape, different concern.
     */
    public function up(): void
    {
        Schema::create('email_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_settings');
    }
};
