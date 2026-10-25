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
        Schema::table('tax_payments', function (Blueprint $table) {
            // What left a foreign-currency account, whose bank sold it to pay the tax (design.md §8.4): typed by hand,
            // since the bank's rate isn't the NBU's
            $table->decimal('original_amount', 15, 2)->nullable()->after('paid_from_account_id');
            $table->char('original_currency', 3)->nullable()->after('original_amount');
            // A receipt has one ЄП and one ВЗ payment, which hasn't failed
            $table->string('receipt_tax_key', 40)->nullable()->after('note')
                ->storedAs("CASE WHEN status <> 'failed' AND payment_id IS NOT NULL THEN CONCAT(payment_id, ':', tax_type) END");
            $table->unique('receipt_tax_key');
        });

        DB::statement(
            'ALTER TABLE tax_payments ADD CONSTRAINT tax_payments_original_amount_with_currency'
            . ' CHECK ((original_amount IS NULL) = (original_currency IS NULL) AND (original_amount IS NULL OR original_amount > 0))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE tax_payments DROP CHECK tax_payments_original_amount_with_currency');

        Schema::table('tax_payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_tax_key']);
            $table->dropColumn(['receipt_tax_key', 'original_amount', 'original_currency']);
        });
    }
};
