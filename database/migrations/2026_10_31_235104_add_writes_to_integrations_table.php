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
        // Whether the integration may change data too (builds.md §7), off until an owner allows it: who and when
        Schema::table('integrations', function (Blueprint $table) {
            $table->timestamp('writes_enabled_at')->nullable()->after('profile_ids');
            $table->foreignId('writes_enabled_by_user_id')->nullable()->after('writes_enabled_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('writes_enabled_by_user_id');
            $table->dropColumn('writes_enabled_at');
        });
    }
};
