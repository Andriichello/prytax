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
        Schema::create('account_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('as_of');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['profile_id', 'account_id', 'as_of']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_opening_balances');
    }
};
