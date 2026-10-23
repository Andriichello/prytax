<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Until English was offered, everyone used PryTax in Ukrainian; api-core's default `en` was never chosen
        DB::table('users')->where('locale', 'en')->update(['locale' => 'uk']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to restore: the users, who chose Ukrainian since, can't be told apart from the ones moved here
    }
};
