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
        // A deletion of a user, a profile or an organization, or the daily rolling cleanup (backups.md §9.4)
        Schema::create('data_retention_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('trigger', 32);
            $table->boolean('immediate')->default(false);
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'status']);
        });

        // A family of a run, done by its handler when due, after the jobs before it
        Schema::create('data_retention_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('data_retention_runs')->cascadeOnDelete();
            $table->string('family', 32);
            $table->string('action', 16);
            $table->unsignedTinyInteger('sequence');
            $table->timestamp('due_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_deleted')->default(0);
            $table->unsignedInteger('records_anonymized')->default(0);
            $table->unsignedInteger('objects_deleted')->default(0);
            // What an earlier job left for this one: the stored objects of the purged rows
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->index(['status', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_retention_jobs');
        Schema::dropIfExists('data_retention_runs');
    }
};
