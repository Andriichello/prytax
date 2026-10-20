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
        // Invoice counterparties, deactivated rather than deleted
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('name_uk')->nullable();
            $table->string('legal_name_uk')->nullable();
            $table->char('country', 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->json('address')->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->string('email')->nullable();
            $table->text('invoice_footer_note')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
