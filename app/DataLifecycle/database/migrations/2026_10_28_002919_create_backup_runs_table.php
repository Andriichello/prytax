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
        // One execution for an organization at a single snapshot instant (backups.md §4.1, §4.3): an archive per profile
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->json('scope');
            // The policy's options at the time of the run, and its profiles (null: every one of the organization)
            $table->json('options');
            $table->json('profile_ids')->nullable();
            $table->string('status', 16)->default('queued');
            $table->timestamp('snapshot_at')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            // The organization's bundle of the run, built on demand and kept for a day (§2.4, §4.5)
            $table->string('bundle_status', 16)->nullable();
            $table->string('bundle_disk', 64)->nullable();
            $table->string('bundle_path', 1024)->nullable();
            $table->timestamp('bundle_expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
