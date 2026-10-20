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
        // Non-taxable cash movements (bank fees, interest, withdrawals), so balances reconcile
        Schema::create('account_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('direction', 3);
            $table->decimal('amount', 15, 2);
            $table->date('booked_at');
            $table->string('category', 16);
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'booked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_movements');
    }
};
