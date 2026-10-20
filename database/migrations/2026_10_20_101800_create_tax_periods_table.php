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
        // The declaration status, deadlines and lock of a quarter; liabilities are computed
        Schema::create('tax_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->boolean('is_annual')->default(false);

            $table->string('declaration_status', 16)->default('none');
            $table->timestamp('declaration_submitted_at')->nullable();
            $table->date('declaration_due_at');
            $table->date('tax_due_at');
            $table->date('esv_due_at');
            $table->timestamp('locked_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['profile_id', 'year', 'quarter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_periods');
    }
};
