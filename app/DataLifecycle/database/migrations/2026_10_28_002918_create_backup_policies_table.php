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
        // An organization's backup policy (backups.md §2.2, §4.1): api-core's organizations aren't extended
        Schema::create('backup_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 16)->default('daily');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->unsignedTinyInteger('retention_daily')->default(7);
            $table->unsignedTinyInteger('retention_weekly')->default(4);
            $table->unsignedTinyInteger('retention_monthly')->default(12);
            $table->boolean('include_documents')->default(true);
            $table->boolean('include_audit_log')->default(false);
            $table->string('profiles_mode', 16)->default('all');
            $table->boolean('skip_unchanged')->default(true);
            $table->string('notify', 16)->default('failures');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
        });

        // The profiles of a policy, which backs up selected ones only
        Schema::create('backup_policy_profiles', function (Blueprint $table) {
            $table->foreignId('backup_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();

            $table->primary(['backup_policy_id', 'profile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_policy_profiles');
        Schema::dropIfExists('backup_policies');
    }
};
