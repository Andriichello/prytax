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
        // Budget requisites of a tax, which change over time, for the copy-ready block of a tax payment
        Schema::create('tax_payment_requisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->string('tax_type', 24);
            $table->string('iban', 34);
            $table->string('recipient_name');
            $table->string('recipient_code', 16);
            // Placeholders: {tax_id} {period} {full_name}
            $table->string('purpose_template');
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['profile_id', 'tax_type', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_payment_requisites');
    }
};
