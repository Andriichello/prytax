<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account's columns, which a set of requisites takes over.
     *
     * @var list<string>
     */
    private const array COLUMNS = ['holder_name', 'bank_name', 'iban', 'swift', 'account_number', 'details', 'invoice_requisites'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // An account's requisites come in sets (the owner, 2026-11-02: Monobank's EUR account takes SEPA and SWIFT
        // transfers, each with its own IBAN, code and receiver): one is the default, any may be switched off
        Schema::create('account_requisite_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // What an invoice prints in parentheses, «Payment details (SEPA)»
            $table->string('name', 64)->nullable();
            $table->string('holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('iban')->nullable();
            $table->text('account_number')->nullable();
            $table->string('swift', 11)->nullable();
            $table->string('bic', 11)->nullable();
            $table->json('details')->nullable();
            $table->json('invoice_requisites')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'sort_order']);
        });

        // The sets an invoice prints, each a «Payment details (…)» section, in order
        Schema::create('invoice_payment_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requisite_set_id')->constrained('account_requisite_sets')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            // The text in the parentheses, null for the set's name
            $table->string('title', 64)->nullable();
            $table->boolean('show_title')->default(false);
            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });

        // Each account with a requisite gets them as its default set; each invoice paid into it prints that set
        DB::table('accounts')->orderBy('id')->get(['id', 'profile_id', ...self::COLUMNS, 'created_at', 'updated_at'])
            ->each(function (object $account) {
                if (!collect(self::COLUMNS)->contains(fn (string $column) => filled($account->{$column}) && $account->{$column} !== '[]')) {
                    return;
                }

                $set = DB::table('account_requisite_sets')->insertGetId([
                    'profile_id' => $account->profile_id,
                    'account_id' => $account->id,
                    ...collect(self::COLUMNS)->mapWithKeys(fn (string $column) => [$column => $account->{$column}])->all(),
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $account->created_at,
                    'updated_at' => $account->updated_at,
                ]);

                DB::table('invoices')->where('receiving_account_id', $account->id)->where('source', '!=', 'virtual')->orderBy('id')
                    ->each(fn (object $invoice) => DB::table('invoice_payment_sections')->insert([
                        'invoice_id' => $invoice->id,
                        'requisite_set_id' => $set,
                        'position' => 1,
                        'title' => null,
                        'show_title' => false,
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->updated_at,
                    ]));
            });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('holder_name')->nullable()->after('currency');
            $table->string('bank_name')->nullable()->after('holder_name');
            $table->text('iban')->nullable()->after('bank_name');
            $table->string('swift', 11)->nullable()->after('iban');
            $table->text('account_number')->nullable()->after('swift');
            $table->json('details')->nullable()->after('account_number');
            $table->json('invoice_requisites')->nullable()->after('details');
        });

        // The default set's requisites go back to its account (a BIC, which the account had no column for, is lost)
        DB::table('account_requisite_sets')->where('is_default', true)->orderBy('id')->get()
            ->each(fn (object $set) => DB::table('accounts')->where('id', $set->account_id)->update([
                ...collect(self::COLUMNS)->mapWithKeys(fn (string $column) => [$column => $set->{$column}])->all(),
                'swift' => $set->swift ?? $set->bic,
            ]));

        Schema::dropIfExists('invoice_payment_sections');
        Schema::dropIfExists('account_requisite_sets');
    }
};
