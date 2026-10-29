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
        // Which receipts a transfer carries, and how much of each, in its currency_sent
        Schema::create('transfer_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('account_transfers')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['transfer_id', 'payment_id']);
        });

        CheckConstraint::add('transfer_allocations', 'transfer_allocations_positive_amount', 'amount > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_allocations');
    }
};
