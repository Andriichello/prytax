<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The database's own row: when it was created, which the starters show (`prytax:summary`, builds.md §6). A new
        // database is created with it; an older one takes the date of its first organization or user
        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at');
        });

        $first = collect(['organizations', 'users'])
            ->map(fn (string $table) => DB::table($table)->min('created_at'))
            ->filter()
            ->min();

        DB::table('installations')->insert(['created_at' => $first ?? now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
