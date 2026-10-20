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
        // Hourly rates of a contract by date, per charge type, or the contract's default without one
        Schema::create('contract_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->decimal('hourly_rate', 15, 2);
            $table->timestamps();
        });

        // A NULL charge type is the contract's default, which is unique per date too
        DB::statement(
            'CREATE UNIQUE INDEX contract_rates_unique'
            . ' ON contract_rates (contract_id, effective_from, (COALESCE(charge_type_id, 0)))'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_rates');
    }
};
