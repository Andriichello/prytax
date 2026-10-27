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
        // Every download link issued of a backup, a bundle or an export (backups.md §3.3)
        Schema::create('backup_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_downloads');
    }
};
