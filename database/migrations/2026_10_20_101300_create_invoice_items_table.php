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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            // The code and the name are copied, so the item survives renames of the lookups
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_code', 32)->nullable();
            $table->foreignId('charge_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('charge_type', 64)->nullable();

            $table->text('description');
            $table->string('unit', 8)->default('hour');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 15, 2);
            // ROUND_HALF_UP(quantity × unit_price, 2)
            $table->decimal('amount', 15, 2);

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
