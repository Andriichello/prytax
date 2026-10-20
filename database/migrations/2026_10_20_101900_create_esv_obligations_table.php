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
        // A month of ЄСВ, generated from the tax rules; its paid state is derived from tax payments
        Schema::create('esv_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('tax_rule_id')->constrained()->restrictOnDelete();
            $table->decimal('amount_due', 10, 2);
            $table->string('status', 8)->default('due');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esv_obligations');
    }
};
