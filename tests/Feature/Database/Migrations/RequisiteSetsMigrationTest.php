<?php

namespace Tests\Feature\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PryTax\Models\Account;
use PryTax\Models\AccountRequisiteSet;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class RequisiteSetsMigrationTest.
 *
 * The migrations of the accounts' requisites into sets (2026-11-02): an account with a requisite gets them, its
 * encrypted IBAN readable, as its default set, which each invoice paid into it prints, but a record without an
 * invoice; an account without any gets no set; the accounts lose the columns. Then a set's BIC becomes its code,
 * labelled «BIC», or beside a SWIFT a field of its own, its place in the order kept. They change the schema, so the
 * database is migrated anew around them, rather than rolled back.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Database\Migrations
 */
class RequisiteSetsMigrationTest extends TestCase
{
    use ActsInProfiles;
    use DatabaseMigrations;

    /**
     * Test that the account's requisites become its default set, which its invoices print.
     *
     * @return void
     */
    public function testMovesAnAccountsRequisitesIntoItsDefaultSet(): void
    {
        [$sets, $labels] = $this->migrations();
        $labels->down();
        $sets->down();
        $profile = $this->createProfile();
        $wise = $this->accountWith($profile, [
            'holder_name' => 'Olena Kovalenko', 'iban' => Crypt::encryptString('BE71967000004410'), 'swift' => 'TRWIBEB1XXX',
            'details' => json_encode([['label' => 'Address', 'value' => 'Brussels']]), 'invoice_requisites' => json_encode([['field' => 'swift', 'shown' => true]]),
        ]);
        $bare = $this->accountWith($profile, []);
        // A record without an invoice prints nothing, so it gets no section
        [$generated] = $this->inProfile($profile, fn () => [
            Invoice::factory()->create(['profile_id' => $profile->id, 'receiving_account_id' => $wise])->id,
            Invoice::factory()->virtual()->create(['profile_id' => $profile->id, 'receiving_account_id' => $wise])->id,
        ]);

        $sets->up();
        $labels->up();

        $this->inProfile($profile, function () use ($wise, $generated) {
            $set = AccountRequisiteSet::query()->sole();
            $this->assertSame([$wise, true, true, null], [$set->account_id, $set->is_default, $set->is_active, $set->name]);
            $this->assertSame(['Olena Kovalenko', 'BE71967000004410', 'TRWIBEB1XXX', null], [$set->holder_name, $set->iban, $set->swift, $set->labels]);
            $this->assertSame([['label' => 'Address', 'value' => 'Brussels']], $set->details);
            $this->assertSame([['field' => 'swift', 'shown' => true]], $set->invoice_requisites);
            $this->assertSame([[$generated, $set->id, 1]], DB::table('invoice_payment_sections')->get()
                ->map(fn (object $section) => [$section->invoice_id, $section->requisite_set_id, $section->position])->all());
        });
        $this->assertSame(0, DB::table('account_requisite_sets')->where('account_id', $bare)->count());
        $this->assertFalse(Schema::hasColumns('accounts', ['holder_name', 'iban', 'details']));
    }

    /**
     * Test that a set's BIC becomes its code labelled «BIC», in the BIC's place of the order, or, beside a SWIFT, a
     * field of its own named «BIC».
     *
     * @return void
     */
    public function testMergesASetsBicIntoItsCode(): void
    {
        [, $labels] = $this->migrations();
        $labels->down();
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $sepa = $this->createRequisiteSet($account);
        $both = $this->createRequisiteSet($account, ['is_default' => false, 'swift' => 'UNJSUAUKXXX']);
        DB::table('account_requisite_sets')->where('id', $sepa->id)->update([
            'bic' => 'CLJUGB21', 'invoice_requisites' => json_encode([['field' => 'bic', 'shown' => true], ['field' => 'holder_name', 'shown' => false]]),
        ]);
        DB::table('account_requisite_sets')->where('id', $both->id)->update(['bic' => 'CLJUGB21']);

        $labels->up();

        $this->inProfile($profile, function () use ($sepa, $both) {
            $sepa->refresh();
            $this->assertSame(['CLJUGB21', ['swift' => 'BIC']], [$sepa->swift, $sepa->labels]);
            $this->assertSame([['field' => 'swift', 'shown' => true], ['field' => 'holder_name', 'shown' => false]], $sepa->invoice_requisites);
            $both->refresh();
            $this->assertSame(['UNJSUAUKXXX', null], [$both->swift, $both->labels]);
            $this->assertSame([['label' => 'BIC', 'value' => 'CLJUGB21']], $both->details);
        });
        $this->assertFalse(Schema::hasColumn('account_requisite_sets', 'bic'));
    }

    /**
     * Get the migrations: the requisites into sets, then their labels with the BIC merged.
     *
     * @return array{0: Migration, 1: Migration}
     */
    private function migrations(): array
    {
        return [
            require database_path('migrations/2026_11_02_121933_move_account_requisites_into_sets.php'),
            require database_path('migrations/2026_11_02_132137_label_requisites_and_merge_bic_into_swift.php'),
        ];
    }

    /**
     * Create an account of the profile, with the requisites in its columns, as they were before the migration.
     *
     * @param Profile $profile
     * @param array<string, string|null> $requisites
     *
     * @return int
     */
    private function accountWith(Profile $profile, array $requisites): int
    {
        $id = $this->inProfile($profile, fn () => Account::factory()->create(['profile_id' => $profile->id])->id);

        if ($requisites !== []) {
            DB::table('accounts')->where('id', $id)->update($requisites);
        }

        return $id;
    }
}
