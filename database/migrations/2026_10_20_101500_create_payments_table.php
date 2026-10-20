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
        // Receipts of client money: each one is exactly one taxable event (design.md §2.1)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16)->default('invoice');
            $table->string('description')->nullable();
            // One wire, which covers several invoices or tranches
            $table->char('receipt_group_id', 36)->nullable();

            // The taxable base, in the invoice's currency
            $table->char('currency', 3);
            $table->decimal('amount', 15, 2);
            // Paid by the client on top: recorded, never income
            $table->decimal('client_fee_amount', 15, 2)->nullable();

            $table->foreignId('received_to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('received_amount', 15, 2);
            $table->char('received_currency', 3);
            // received_amount / amount, when the currencies differ
            $table->decimal('receipt_rate', 12, 4)->nullable();
            // The tax date: the day the money landed on received_to_account
            $table->date('received_at');

            // The snapshot, recomputed only when amount, currency or received_at change
            $table->decimal('nbu_rate', 12, 4);
            $table->date('nbu_rate_date');
            $table->foreignId('exchange_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('uah_amount', 15, 2);
            $table->foreignId('tax_rule_id')->constrained()->restrictOnDelete();
            $table->decimal('single_tax_uah', 15, 2);
            $table->decimal('military_levy_uah', 15, 2);

            $table->unsignedSmallInteger('period_year')->storedAs('YEAR(`received_at`)');
            $table->unsignedTinyInteger('period_quarter')->storedAs('QUARTER(`received_at`)');
            $table->unsignedTinyInteger('period_month')->storedAs('MONTH(`received_at`)');

            $table->timestamp('repatriation_ack_at')->nullable();
            // The FX transfer, of which a synthetic fx_gain row is made (deleted with it)
            $table->foreignId('source_transfer_id')->nullable()->constrained('account_transfers')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['profile_id', 'period_year', 'period_quarter']);
            $table->index(['received_to_account_id', 'received_at']);
            $table->index('receipt_group_id');
        });

        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_invoice_of_invoice_kind"
            . " CHECK (kind <> 'invoice' OR invoice_id IS NOT NULL)"
        );
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_positive_amount CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
