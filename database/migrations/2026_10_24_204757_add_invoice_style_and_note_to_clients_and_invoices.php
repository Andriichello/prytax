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
        // The style and the note of a client's invoices (design.md §4.1), each null to take the profile's; the note
        // replaces the client's footer note
        Schema::table('clients', function (Blueprint $table) {
            $table->string('invoice_style', 32)->nullable()->after('show_contract');
            $table->boolean('invoice_note_enabled')->nullable()->after('invoice_style');
            $table->text('invoice_note')->nullable()->after('invoice_note_enabled');
            $table->string('invoice_note_kind', 16)->nullable()->after('invoice_note');
        });

        // The invoice's own style and note, each null to take the client's
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('style', 32)->nullable()->after('show_contract');
            $table->boolean('note_enabled')->nullable()->after('style');
            $table->text('note')->nullable()->after('note_enabled');
            $table->string('note_kind', 16)->nullable()->after('note');
        });

        // A footer note printed under every invoice of its level: the client's when it had one, else the profile's.
        // Each becomes its level's own note, so a client without one still takes the profile's
        DB::table('clients')
            ->whereNotNull('invoice_footer_note')
            ->whereRaw("trim(invoice_footer_note) <> ''")
            ->update([
                'invoice_note' => DB::raw('invoice_footer_note'),
                'invoice_note_enabled' => true,
                'invoice_note_kind' => 'other',
            ]);

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('invoice_footer_note');
        });

        $this->moveProfileFooterNotes();
    }

    /**
     * Make each profile's footer note its own note, switched on, of the kind `other`.
     *
     * @return void
     */
    protected function moveProfileFooterNotes(): void
    {
        $this->moveSettings('invoice.footer_note', fn (object $row) => [
            'invoice.note' => $row->value,
            'invoice.note_enabled' => 'true',
            'invoice.note_kind' => '"other"',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('invoice_footer_note')->nullable()->after('email');
        });

        DB::table('clients')
            ->where('invoice_note_enabled', true)
            ->update(['invoice_footer_note' => DB::raw('invoice_note')]);

        $enabled = DB::table('settings')->where('key', 'invoice.note_enabled')->where('value', 'true')->get();

        foreach ($enabled as $row) {
            $note = DB::table('settings')
                ->where(['model_type' => $row->model_type, 'model_id' => $row->model_id, 'key' => 'invoice.note'])
                ->first();

            if ($note !== null) {
                DB::table('settings')->insert([
                    'model_type' => $row->model_type,
                    'model_id' => $row->model_id,
                    'key' => 'invoice.footer_note',
                    'value' => $note->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('settings')->whereIn('key', ['invoice.style', 'invoice.note', 'invoice.note_enabled', 'invoice.note_kind'])->delete();

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['style', 'note_enabled', 'note', 'note_kind']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['invoice_style', 'invoice_note_enabled', 'invoice_note', 'invoice_note_kind']);
        });
    }

    /**
     * Replace each stored setting of the key, which has a value, with the settings the callback gives (keys with
     * their JSON values), for the same model.
     *
     * @param string $key
     * @param callable(object): array<string, string> $replacements
     *
     * @return void
     */
    protected function moveSettings(string $key, callable $replacements): void
    {
        $rows = DB::table('settings')->where('key', $key)->get();

        foreach ($rows as $row) {
            $text = json_decode((string) $row->value);

            if (is_string($text) && trim($text) !== '') {
                foreach ($replacements($row) as $newKey => $value) {
                    DB::table('settings')->insert([
                        'model_type' => $row->model_type,
                        'model_id' => $row->model_id,
                        'key' => $newKey,
                        'value' => $value,
                        'created_at' => $row->created_at,
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('settings')->where('key', $key)->delete();
    }
};
