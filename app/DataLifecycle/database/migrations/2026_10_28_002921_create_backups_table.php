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
        // A profile's archive of a run (backups.md §4.1), or its outcome: skipped as unchanged, failed, pruned. The profile
        // can't be deleted while it has backups; its purge deletes them first (§9.3)
        Schema::create('backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('backup_runs');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->string('status', 24);
            $table->string('tier', 16);
            $table->string('disk', 64)->nullable();
            $table->string('object_path', 1024)->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->json('row_counts')->nullable();
            $table->json('manifest')->nullable();
            $table->unsignedSmallInteger('format_version')->nullable();
            $table->char('registry_hash', 64)->nullable();
            $table->string('schema_version')->nullable();
            $table->boolean('include_documents')->default(true);
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'profile_id']);
            $table->index(['organization_id', 'profile_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
