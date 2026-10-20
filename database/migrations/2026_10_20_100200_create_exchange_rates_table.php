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
        // A cache of NBU rates and manual overrides, never the truth for historical rows
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 12, 4);
            $table->string('source', 8)->default('nbu');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['currency', 'rate_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
