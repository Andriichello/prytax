<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PryTax\Helpers\CheckConstraint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Payments of ЄП, ВЗ and ЄСВ, allocated to a receipt, a month or a quarter
        Schema::create('tax_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('tax_type', 24);
            $table->decimal('amount', 15, 2);
            $table->date('paid_at')->nullable();
            $table->string('status', 8)->default('sent');

            $table->string('allocation', 8);
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            // Always filled, so a quarter is reconciled with one indexed query
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_quarter');
            $table->unsignedTinyInteger('period_month')->nullable();

            $table->foreignId('paid_from_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(
                ['profile_id', 'tax_type', 'period_year', 'period_quarter', 'status'],
                'tax_payments_reconciliation_index'
            );
            $table->index(['paid_from_account_id', 'paid_at']);
        });

        foreach ([
            'tax_payments_payment_of_payment_allocation' => "allocation <> 'payment' OR payment_id IS NOT NULL",
            'tax_payments_month_of_month_allocation' => "allocation <> 'month' OR period_month IS NOT NULL",
            'tax_payments_no_month_of_quarter_allocation' => "allocation <> 'quarter' OR period_month IS NULL",
            'tax_payments_positive_amount' => 'amount > 0',
            'tax_payments_date_of_paid' => "status <> 'paid' OR paid_at IS NOT NULL",
        ] as $name => $check) {
            CheckConstraint::add('tax_payments', $name, $check);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_payments');
    }
};
