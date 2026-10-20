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
        // Money returned to a client: reduces income in the period of the refund (п. 292.11)
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->date('refunded_at');

            $table->decimal('nbu_rate', 12, 4);
            $table->date('nbu_rate_date');
            $table->foreignId('exchange_rate_id')->nullable()->constrained()->nullOnDelete();
            // Entered by the user, as the NBU's rate was unavailable
            $table->boolean('rate_is_manual')->default(false);
            $table->decimal('uah_amount', 15, 2);
            $table->foreignId('tax_rule_id')->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();

            $table->unsignedSmallInteger('period_year')->storedAs('YEAR(`refunded_at`)');
            $table->unsignedTinyInteger('period_quarter')->storedAs('QUARTER(`refunded_at`)');
            $table->unsignedTinyInteger('period_month')->storedAs('MONTH(`refunded_at`)');

            $table->timestamps();

            $table->index(['profile_id', 'period_year', 'period_quarter']);
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_positive_amount CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
