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
        // The order of the requisites in an invoice's payment details, and which of them print (invoice.md §4): null
        // prints them all in the built-in order
        Schema::table('accounts', function (Blueprint $table) {
            $table->json('invoice_requisites')->nullable()->after('details');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('invoice_requisites');
        });
    }
};
