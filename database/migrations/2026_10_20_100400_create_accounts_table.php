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
        // Places, where money lands: receipt destinations, transfer endpoints and tax payment sources
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('kind', 16);
            $table->char('currency', 3);

            $table->string('holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('iban')->nullable();
            $table->string('swift', 11)->nullable();
            $table->text('account_number')->nullable();
            $table->json('details')->nullable();

            $table->decimal('limit_amount', 15, 2)->nullable();
            $table->string('limit_period', 8)->nullable();
            $table->string('limit_window', 8)->default('calendar');
            $table->string('limit_counts', 16)->default('receipts');

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['profile_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
