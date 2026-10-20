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
        // Seeds the running balance of a tax, when the books start mid-history: + overpaid, − owed
        Schema::create('tax_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tax_type', 24);
            $table->date('as_of');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['profile_id', 'tax_type', 'as_of']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_opening_balances');
    }
};
