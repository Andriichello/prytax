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
        // Movements between the profile's own accounts: never income
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount_sent', 15, 2);
            $table->char('currency_sent', 3);
            $table->decimal('amount_received', 15, 2);
            $table->char('currency_received', 3);
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->char('fee_currency', 3)->nullable();
            // FX transfers only: NBU rate of currency_sent on received_at
            $table->decimal('nbu_rate', 12, 4)->nullable();
            // Entered by the user, as the NBU's rate was unavailable
            $table->boolean('rate_is_manual')->default(false);

            $table->date('sent_at')->nullable();
            $table->date('received_at');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['profile_id', 'received_at']);
        });

        DB::statement(
            'ALTER TABLE account_transfers ADD CONSTRAINT account_transfers_distinct_accounts'
            . ' CHECK (from_account_id <> to_account_id)'
        );
        DB::statement(
            'ALTER TABLE account_transfers ADD CONSTRAINT account_transfers_positive_amounts'
            . ' CHECK (amount_sent > 0 AND amount_received >= 0)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_transfers');
    }
};
