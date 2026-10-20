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
        // Notifications of a profile ("Сповіщення"), one per condition, upserted by AlertSync
        // and resolved when the condition clears; api-core's `notifications` are per user
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->string('type', 48);
            $table->string('category', 16);
            $table->string('severity', 8);
            $table->string('dedupe_key', 120);
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('action_label', 64)->nullable();
            $table->string('action_route', 120)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'dedupe_key']);
            $table->index(['profile_id', 'resolved_at', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
