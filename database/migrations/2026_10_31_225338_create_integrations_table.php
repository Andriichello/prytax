<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // An organization's integrations (builds.md §7), off until its owner turns one on in Налаштування →
        // Інтеграції: who did it and when, how it's configured, and what its other side last did
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('profile_ids')->nullable();
            // The connection's key, encrypted with the installation's key, and its SHA-256, which finds it
            $table->text('key')->nullable();
            $table->char('key_hash', 64)->nullable()->unique();
            $table->timestamp('key_created_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->json('last_client')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_tool', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
