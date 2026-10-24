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
        // The invoice settings of a client (design.md §4.1), each null to take the profile's; its currency exists
        Schema::table('clients', function (Blueprint $table) {
            $table->string('invoice_locale', 5)->nullable()->after('invoice_footer_note');
            $table->string('payment_purpose_template', 255)->nullable()->after('invoice_locale');
            $table->boolean('act_note_enabled')->nullable()->after('payment_purpose_template');
            $table->string('act_note', 500)->nullable()->after('act_note_enabled');
            $table->boolean('show_contract')->nullable()->after('act_note');
        });

        // What the contract's services are, for the {services} of the payment purpose
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('subject', 100)->nullable()->after('number');
        });

        // The invoice's own settings, each null to take the client's
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('currency');
            $table->string('payment_purpose_template', 255)->nullable()->after('locale');
            $table->boolean('act_note_enabled')->nullable()->after('payment_purpose_template');
            $table->string('act_note', 500)->nullable()->after('act_note_enabled');
            $table->boolean('show_contract')->nullable()->after('act_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['locale', 'payment_purpose_template', 'act_note_enabled', 'act_note', 'show_contract']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('subject');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['invoice_locale', 'payment_purpose_template', 'act_note_enabled', 'act_note', 'show_contract']);
        });
    }
};
