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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('receiving_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Null until the freeze allocates it, unless the user typed one
            $table->string('number', 64)->nullable();
            // Both null for a number, which the user typed
            $table->string('sequence_scope_key', 64)->nullable();
            $table->unsignedInteger('sequence_number')->nullable();
            $table->string('source', 16)->default('generated');

            $table->date('period_start');
            $table->date('period_end');
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->char('currency', 3);

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('adjustment_amount', 15, 2)->default(0);
            $table->string('adjustment_label')->nullable();
            // subtotal + adjustment_amount
            $table->decimal('total', 15, 2)->default(0);

            $table->string('status', 16)->default('draft');
            $table->timestamp('locked_at')->nullable();
            $table->date('sent_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('cancelled_at')->nullable();

            // {schema_version, money_format, profile, client, contract, account, items, totals, footer_note}
            $table->json('snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('primary_file_id')->nullable()->constrained('files')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['profile_id', 'number']);
            $table->index(['profile_id', 'client_id', 'status']);
            $table->index(['profile_id', 'issued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
