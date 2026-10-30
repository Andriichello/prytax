<?php

namespace Tests\Feature\Helpers;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PryTax\Helpers\Sql;
use PryTax\Models\Payment;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class SqlTest.
 *
 * SQL that MySQL and SQLite write differently (builds.md §3.1): the dates' parts, conditions and joined
 * strings, Ukrainian letters' cases, and the CHECKs of the tables, which give the same results on either; the suite
 * runs on both (`composer test` and `composer test:sqlite`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Helpers
 */
class SqlTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the year, quarter and month of a date, a condition and joined strings are the same on either database.
     *
     * @return void
     */
    public function testComputesTheSameDatePartsAndStrings(): void
    {
        $date = "'2026-11-30'";

        $row = DB::selectOne(
            'SELECT ' . Sql::year($date) . ' AS y, ' . Sql::quarter($date) . ' AS q, ' . Sql::month($date) . ' AS m, '
            . Sql::when('1 = 2', "'yes'", "'no'") . ' AS w, ' . Sql::concat(["'2026'", "'-Q'", '4']) . ' AS c'
        );

        $this->assertSame([2026, 4, 11, 'no', '2026-Q4'], [(int) $row->y, (int) $row->q, (int) $row->m, $row->w, $row->c]);
    }

    /**
     * Test that LOWER(), UPPER() and LIKE ignore the case of Ukrainian letters, as MySQL's collation does.
     *
     * @return void
     */
    public function testFoldsTheCaseOfUkrainianLetters(): void
    {
        $row = DB::selectOne(
            "SELECT LOWER('ОЛЕНА Їжак') AS lower_name, UPPER('ґанок') AS upper_name,"
            . " CASE WHEN 'Олена Коваленко' LIKE 'олена%' THEN 1 ELSE 0 END AS starts,"
            . " CASE WHEN 'Олена' LIKE 'ол_на' THEN 1 ELSE 0 END AS one_character,"
            . " CASE WHEN 'Олена' LIKE 'Ірина' THEN 1 ELSE 0 END AS other"
        );

        $this->assertSame('олена їжак', $row->lower_name);
        $this->assertSame('ҐАНОК', $row->upper_name);
        $this->assertSame([1, 1, 0], [(int) $row->starts, (int) $row->one_character, (int) $row->other]);
    }

    /**
     * Test that a CHECK of a table refuses a row, which breaks it, and takes one, which keeps it.
     *
     * @return void
     */
    public function testChecksTheRowsOfATable(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $payment = $this->recordReceipt($profile, $this->createFrozenInvoice($profile, $account), $account);

        DB::table('payments')->where('id', $payment->id)->update(['amount' => '10.00']);

        $this->assertSame('10.00', Payment::query()->withoutGlobalScopes()->findOrFail($payment->id)->amount);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/payments_positive_amount/');

        DB::table('payments')->where('id', $payment->id)->update(['amount' => '-10.00']);
    }
}
