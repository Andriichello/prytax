<?php

namespace Tests\Feature\Services\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Reports\AccountingExporter;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountingExporterTest.
 *
 * CSV exports (design.md §7, suggestions §3.15): the quarter's rows with their fees, net and summary, the income book
 * with each document and its month's and cumulative totals, and the year's receipts and taxes; in the profile's
 * decimal separator, with semicolons for a decimal comma, and named in the language of the request.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class AccountingExporterTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a profile with a receipt of 1 000 USD at 41.0000 on 05.10.2026, of which 985 landed.
     *
     * @param string $moneyFormat
     *
     * @return Profile
     */
    private function profileWithReceipt(string $moneyFormat = 'space_dot'): Profile
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, function () use ($profile, $moneyFormat) {
            $profile->updateSettings(['display.money_format' => $moneyFormat]);
            Payment::factory()->create([
                'profile_id' => $profile->id, 'received_to_account_id' => $this->createAccount($profile)->id,
                'received_amount' => '985.00', 'description' => 'Бонус платформи',
            ]);
        });

        return $profile;
    }

    /**
     * Get the rows of a CSV, without its byte order mark.
     *
     * @param string $csv
     * @param string $separator
     *
     * @return array<int, array<int, string>>
     */
    private function rows(string $csv, string $separator = ','): array
    {
        $lines = array_filter(explode("\n", substr($csv, 3)));

        return array_values(array_map(fn (string $line) => str_getcsv($line, $separator, escape: ''), $lines));
    }

    /**
     * Test that the quarter's CSV has each receipt's fees and net, and the quarter's summary.
     *
     * @return void
     */
    public function testExportsTheQuarterWithFeesNetAndSummary(): void
    {
        App::setLocale('uk');
        $profile = $this->profileWithReceipt();

        $rows = $this->rows($this->inProfile($profile, fn () => app(AccountingExporter::class)->quarter($profile, 2026, 4)));

        $this->assertSame(['Комісії', 'Чисто'], array_slice($rows[0], 9, 2));
        $this->assertSame(['2026-10-05', '', 'Бонус платформи', 'USD', '1000.00', '41.0000', '41000.00', '2050.00', '410.00', '15.00', '925.00'], array_slice($rows[1], 0, 11));
        $this->assertContains(['ЄП до сплати, ₴', '2050.00', 'окремо', '2050.00', 'за надходженнями', '2050.00'], $rows);
    }

    /**
     * Test that the columns and the summary are named in English, when the request is in English.
     *
     * @return void
     */
    public function testNamesTheColumnsInEnglish(): void
    {
        App::setLocale('en');
        $profile = $this->profileWithReceipt();

        $rows = $this->rows($this->inProfile($profile, fn () => app(AccountingExporter::class)->quarter($profile, 2026, 4)));

        $this->assertSame(['Date', 'Document', 'Client / description'], array_slice($rows[0], 0, 3));
        $this->assertSame(['Single tax', 'Military levy', 'Fees', 'Net'], array_slice($rows[0], 7, 4));
        $this->assertContains(['Single tax owed, ₴', '2050.00', 'standalone', '2050.00', 'by receipts', '2050.00'], $rows);
    }

    /**
     * Test that the income book lists each document with its month's and the cumulative totals.
     *
     * @return void
     */
    public function testExportsEachDocumentOfTheIncomeBook(): void
    {
        App::setLocale('uk');
        $profile = $this->profileWithReceipt();

        $rows = $this->rows($this->inProfile($profile, fn () => app(AccountingExporter::class)->incomeBook($profile, 2026)));

        $this->assertContains(['2026-10-05', '', 'Бонус платформи', 'USD', '1000.00', '41.0000', '41000.00', '', ''], $rows);
        $this->assertContains(['2026-10', 'Підсумок місяця', '', '', '', '', '', '41000.00', '41000.00'], $rows);
        $this->assertSame(['Рік', 'Підсумок року', '', '', '', '', '', '41000.00', '41000.00'], end($rows));
    }

    /**
     * Test that with a decimal comma the amounts have it, and the columns are separated by semicolons.
     *
     * @return void
     */
    public function testUsesTheProfilesDecimalComma(): void
    {
        $profile = $this->profileWithReceipt('space_comma');

        $rows = $this->rows($this->inProfile($profile, fn () => app(AccountingExporter::class)->year($profile, 2026)), ';');

        $this->assertContains(['2026-10-05', '', 'Бонус платформи', 'USD', '1000,00', '41,0000', '41000,00', '2050,00', '410,00', '15,00', '925,00', 'Wise USD'], $rows);
    }
}
