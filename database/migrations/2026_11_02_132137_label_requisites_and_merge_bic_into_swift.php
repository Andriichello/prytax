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
        // A set's fields print under the labels its owner gives them («Beneficiary name» for the receiver), else the
        // invoice's language's (the owner, 2026-11-02); SWIFT and BIC are one code, labelled as the bank names it
        Schema::table('account_requisite_sets', function (Blueprint $table) {
            $table->json('labels')->nullable()->after('details');
        });

        DB::table('account_requisite_sets')->whereNotNull('bic')->orderBy('id')->get()->each(function (object $set) {
            $labels = json_decode((string) $set->labels, true) ?: [];
            $details = json_decode((string) $set->details, true) ?: [];
            $order = json_decode((string) $set->invoice_requisites, true);

            // The BIC becomes the code, labelled «BIC», unless the set has a SWIFT already: then one of its own fields
            if ($set->swift === null) {
                $changes = ['swift' => $set->bic, 'labels' => json_encode([...$labels, 'swift' => 'BIC'])];
                $entry = ['field' => 'swift'];
            } else {
                $changes = ['details' => json_encode([...$details, ['label' => 'BIC', 'value' => $set->bic]])];
                $entry = ['label' => 'BIC'];
            }

            if (is_array($order)) {
                $changes['invoice_requisites'] = json_encode(array_map(
                    fn (array $item) => ($item['field'] ?? null) === 'bic' ? [...$entry, 'shown' => $item['shown'] ?? true] : $item,
                    $order,
                ));
            }

            DB::table('account_requisite_sets')->where('id', $set->id)->update($changes);
        });

        Schema::table('account_requisite_sets', function (Blueprint $table) {
            $table->dropColumn('bic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_requisite_sets', function (Blueprint $table) {
            $table->string('bic', 11)->nullable()->after('swift');
        });

        // A code labelled «BIC» goes back to the BIC; the other labels are lost
        DB::table('account_requisite_sets')->whereNotNull('labels')->orderBy('id')->get()->each(function (object $set) {
            $labels = json_decode((string) $set->labels, true) ?: [];

            if (($labels['swift'] ?? null) === 'BIC' && $set->swift !== null) {
                DB::table('account_requisite_sets')->where('id', $set->id)->update(['bic' => $set->swift, 'swift' => null]);
            }
        });

        Schema::table('account_requisite_sets', function (Blueprint $table) {
            $table->dropColumn('labels');
        });
    }
};
