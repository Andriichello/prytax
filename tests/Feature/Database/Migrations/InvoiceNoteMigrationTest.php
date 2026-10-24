<?php

namespace Tests\Feature\Database\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoiceNoteMigrationTest.
 *
 * The migration of the invoice note (design.md §4.1) turns a ФОП's footer note, which printed under every invoice of
 * a client without its own one, into the ФОП's own note: switched on, of the kind `other`. A blank one is dropped.
 * (The clients' footer notes move the same way in its column update, which needs the DDL around it.)
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Database\Migrations
 */
class InvoiceNoteMigrationTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a profile's footer note becomes its note, switched on and of the kind `other`, and a blank one goes.
     *
     * @return void
     */
    public function testMovesAProfilesFooterNoteIntoItsNote(): void
    {
        $noted = $this->createProfile();
        $blank = $this->createProfile();
        $morph = $noted->getMorphClass();

        DB::table('settings')->insert([
            ['model_type' => $morph, 'model_id' => $noted->id, 'key' => 'invoice.footer_note', 'value' => json_encode('Reverse charge.')],
            ['model_type' => $morph, 'model_id' => $blank->id, 'key' => 'invoice.footer_note', 'value' => json_encode('  ')],
        ]);

        $this->moveFooterNotes();

        $this->assertSame([
            'invoice.note' => 'Reverse charge.',
            'invoice.note_enabled' => true,
            'invoice.note_kind' => 'other',
        ], $this->settingsOf($noted->id));
        $this->assertSame([], $this->settingsOf($blank->id));
    }

    /**
     * Move the footer notes of the settings, as the migration does.
     *
     * @return void
     */
    private function moveFooterNotes(): void
    {
        $migration = require database_path('migrations/2026_10_24_204757_add_invoice_style_and_note_to_clients_and_invoices.php');

        (new ReflectionMethod($migration, 'moveProfileFooterNotes'))->invoke($migration);
    }

    /**
     * Get the stored invoice settings of the profile, by key.
     *
     * @param int $profileId
     *
     * @return array<string, mixed>
     */
    private function settingsOf(int $profileId): array
    {
        return DB::table('settings')
            ->where('model_id', $profileId)
            ->where('key', 'like', 'invoice.%')
            ->orderBy('key')
            ->pluck('value', 'key')
            ->map(fn (string $value) => json_decode($value, true))
            ->all();
    }
}
