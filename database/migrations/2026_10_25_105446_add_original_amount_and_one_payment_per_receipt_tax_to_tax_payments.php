<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PryTax\Helpers\CheckConstraint;
use PryTax\Helpers\Sql;

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
            // A receipt has one ЄП and one ВЗ payment, which hasn't failed. SQLite can't add a stored column to a
            // table, so there it's virtual, which its unique index takes as well
            $receiptTaxKey = "CASE WHEN status <> 'failed' AND payment_id IS NOT NULL THEN " . Sql::concat(['payment_id', "':'", 'tax_type']) . ' END';
            $column = $table->string('receipt_tax_key', 40)->nullable()->after('note');
            Sql::isSqlite() ? $column->virtualAs($receiptTaxKey) : $column->storedAs($receiptTaxKey);
            $table->unique('receipt_tax_key');
        });

        CheckConstraint::add(
            'tax_payments',
            'tax_payments_original_amount_with_currency',
            '(original_amount IS NULL) = (original_currency IS NULL) AND (original_amount IS NULL OR original_amount > 0)',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        CheckConstraint::drop('tax_payments', 'tax_payments_original_amount_with_currency');

        Schema::table('tax_payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_tax_key']);
            $table->dropColumn(['receipt_tax_key', 'original_amount', 'original_currency']);
        });
    }
};
