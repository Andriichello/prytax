<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class VerifyDatabaseTest.
 *
 * The check of a restored database (backups.md §1.1): a sound one passes with its ФОП's tax balances; a row, whose
 * foreign key points at nothing, or a tax id, which the app's keys can't decrypt, fails it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class VerifyDatabaseTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a sound database passes, with the tax balances of its ФОП.
     *
     * @return void
     */
    public function testPassesASoundDatabase(): void
    {
        $profile = $this->createProfile();

        $this->artisan('prytax:verify-db')
            ->expectsOutputToContain("Profile $profile->id: single_tax ")
            ->assertSuccessful();
    }

    /**
     * Test that an orphaned foreign key and an undecryptable tax id fail the check.
     *
     * @return void
     */
    public function testFailsOnOrphansAndUnreadableEncryption(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        // SQLite can't turn the checks off inside the test's transaction, but defers them to its commit, which never comes
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::withoutForeignKeyConstraints(fn () => DB::table('account_movements')->insert([
            'profile_id' => $profile->id, 'account_id' => $account->id + 1000, 'direction' => 'in', 'amount' => '1.00',
            'booked_at' => '2026-10-01', 'category' => 'other',
        ]));
        DB::table('profiles')->where('id', $profile->id)->update(['tax_id' => 'not a ciphertext']);

        $this->artisan('prytax:verify-db')
            ->expectsOutputToContain('account_movements.account_id: 1 rows reference no accounts row')
            ->expectsOutputToContain("Profile $profile->id: its tax id can't be decrypted")
            ->assertFailed();
    }
}
