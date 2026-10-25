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
        // Two independent notes at each level (design.md §4.1): the payment note under the payment details, the other
        // note under the act; each null to take the next level's
        foreach (['clients' => 'invoice_style', 'invoices' => 'style'] as $table => $after) {
            Schema::table($table, function (Blueprint $table) use ($after) {
                $table->boolean('payment_note_enabled')->nullable()->after($after);
                $table->text('payment_note')->nullable()->after('payment_note_enabled');
                $table->boolean('other_note_enabled')->nullable()->after('payment_note');
                $table->text('other_note')->nullable()->after('other_note_enabled');
            });
        }

        // A level's note becomes the note of its kind, with its switch
        $this->moveColumns('clients', 'invoice_note_enabled', 'invoice_note', 'invoice_note_kind');
        $this->moveColumns('invoices', 'note_enabled', 'note', 'note_kind');
        $this->moveSettings();

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['invoice_note_enabled', 'invoice_note', 'invoice_note_kind']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['note_enabled', 'note', 'note_kind']);
        });
    }

    /**
     * Move the note of each row of the table into the payment note, when its kind is `payment`, or the other note.
     *
     * @param string $table
     * @param string $enabled
     * @param string $note
     * @param string $kind
     *
     * @return void
     */
    protected function moveColumns(string $table, string $enabled, string $note, string $kind): void
    {
        DB::table($table)->whereNotNull($enabled)->where($kind, 'payment')->update([
            'payment_note_enabled' => DB::raw($enabled),
            'payment_note' => DB::raw($note),
        ]);

        DB::table($table)->whereNotNull($enabled)->where(fn ($query) => $query->whereNull($kind)->orWhere($kind, '<>', 'payment'))->update([
            'other_note_enabled' => DB::raw($enabled),
            'other_note' => DB::raw($note),
        ]);
    }

    /**
     * Move each profile's stored note setting into the setting of its kind's note.
     *
     * @return void
     */
    protected function moveSettings(): void
    {
        $rows = DB::table('settings')->whereIn('key', ['invoice.note_enabled', 'invoice.note', 'invoice.note_kind'])->get();

        foreach ($rows->groupBy(fn (object $row) => "{$row->model_type}:{$row->model_id}") as $settings) {
            $byKey = $settings->keyBy('key');
            $prefix = json_decode((string) $byKey->get('invoice.note_kind')?->value) === 'payment' ? 'payment' : 'other';

            foreach (['invoice.note_enabled' => "invoice.{$prefix}_note_enabled", 'invoice.note' => "invoice.{$prefix}_note"] as $old => $new) {
                $row = $byKey->get($old);

                if ($row !== null) {
                    DB::table('settings')->insert([
                        'model_type' => $row->model_type,
                        'model_id' => $row->model_id,
                        'key' => $new,
                        'value' => $row->value,
                        'created_at' => $row->created_at,
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('settings')->whereIn('key', ['invoice.note_enabled', 'invoice.note', 'invoice.note_kind'])->delete();
    }

    /**
     * Reverse the migrations. A client or an invoice with both notes keeps its payment note only, since the old note
     * was one; the profiles' notes are dropped.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('invoice_note_enabled')->nullable()->after('invoice_style');
            $table->text('invoice_note')->nullable()->after('invoice_note_enabled');
            $table->string('invoice_note_kind', 16)->nullable()->after('invoice_note');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('note_enabled')->nullable()->after('style');
            $table->text('note')->nullable()->after('note_enabled');
            $table->string('note_kind', 16)->nullable()->after('note');
        });

        foreach (['clients' => 'invoice_note', 'invoices' => 'note'] as $table => $note) {
            foreach (['other', 'payment'] as $kind) {
                DB::table($table)->whereNotNull("{$kind}_note_enabled")->update([
                    "{$note}_enabled" => DB::raw("{$kind}_note_enabled"),
                    $note => DB::raw("{$kind}_note"),
                    "{$note}_kind" => $kind,
                ]);
            }

            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['payment_note_enabled', 'payment_note', 'other_note_enabled', 'other_note']);
            });
        }

        DB::table('settings')
            ->whereIn('key', ['invoice.payment_note_enabled', 'invoice.payment_note', 'invoice.other_note_enabled', 'invoice.other_note'])
            ->delete();
    }
};
