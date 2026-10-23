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
        // A notification's title, body, action and context line in each language of the app: {"uk": {...}, "en": {...}}
        Schema::table('alerts', function (Blueprint $table) {
            $table->json('texts')->nullable()->after('action_route');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('texts');
        });
    }
};
