<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings, which the «Minimal» phase removes (design.md v4): the taxable base is always the invoiced amount, and
     * taxes are shown by receipt only.
     *
     * @var array<int, string>
     */
    protected array $removedSettings = ['income.taxable_base', 'taxes.default_grouping'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // An account's limit counts the client receipts within a calendar window only (design.md §3, accounts)
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['limit_window', 'limit_counts']);
        });

        DB::table('settings')->whereIn('key', $this->removedSettings)->delete();
    }

    /**
     * Reverse the migrations. The removed settings come back with their defaults, since their values are gone.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('limit_window', 8)->default('calendar')->after('limit_period');
            $table->string('limit_counts', 16)->default('receipts')->after('limit_window');
        });
    }
};
