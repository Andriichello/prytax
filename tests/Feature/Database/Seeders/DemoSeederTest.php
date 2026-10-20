<?php

namespace Tests\Feature\Database\Seeders;

use Core\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PryTax\Models\Profile;
use Tests\TestCase;

/**
 * Class DemoSeederTest.
 *
 * The sample practice matches the figures of the design's mock screens, read through the API: Облік of III and
 * IV кв. 2026, the ЄП balance, the account balances and the practice overview.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Database\Seeders
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the API answers the mock's figures for Коваленко О. П.
     *
     * @return void
     */
    public function testSeedsTheFiguresOfTheMockScreens(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 12:00:00');
        $this->seed(DemoSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'olena@example.com')->firstOrFail());
        $profile = Profile::query()->withoutGlobalScopes()->where('tax_id_hash', Profile::hashTaxId('3214704821'))->firstOrFail();
        $path = "/api/profiles/{$profile->id}";

        $this->getJson("$path/accounting/2026/3")
            ->assertOk()
            ->assertJsonPath('data.summary.quarter_income', '503118.73')
            ->assertJsonPath('data.summary.ytd_income', '1487175.04')
            ->assertJsonPath('data.summary.single_tax.owed', '25155.93')
            ->assertJsonPath('data.summary.single_tax.standalone_delta', '-0.01')
            ->assertJsonPath('data.summary.military_levy.owed', '5031.19')
            ->assertJsonPath('data.summary.esv.ytd', '17121.06')
            ->assertJsonPath('data.summary.cap.percent', '14.7')
            ->assertJsonCount(4, 'data.rows');
        $this->getJson("$path/accounting/2026/4")
            ->assertOk()
            ->assertJsonPath('data.summary.quarter_income', '352960.01')
            ->assertJsonPath('data.summary.ytd_income', '1840135.05')
            ->assertJsonPath('data.summary.single_tax.owed', '17648.00')
            ->assertJsonPath('data.summary.single_tax.hints_sum', '17648.01')
            ->assertJsonPath('data.summary.military_levy.owed', '3529.60')
            ->assertJsonPath('data.summary.cap.percent', '18.2');

        $this->getJson("$path/taxes/2026?tax_type=single_tax")
            ->assertOk()
            ->assertJsonPath('data.balance', '-18413.69')
            ->assertJsonPath('data.quarters.2.balance', '-765.69')
            ->assertJsonPath('data.quarters.3.pending', '8218.96');

        $balances = collect($this->getJson("$path/accounts?append=balance")->assertOk()->json('data'))
            ->mapWithKeys(fn (array $account) => ["{$account['name']} {$account['currency']}" => $account['balance']]);
        $this->assertSame('8217.50', $balances['Wise USD USD']);
        $this->assertSame('1540.00', $balances['Wise EUR EUR']);
        $this->assertSame('312.40', $balances['Payoneer USD USD']);
        $this->assertSame('286410.37', $balances['ПриватБанк ФОП UAH']);
        $this->assertSame('2650.00', $balances['ПриватБанк ФОП USD']);

        $this->getJson('/api/overview/2026/4')
            ->assertOk()
            ->assertJsonCount(3, 'data.profiles')
            ->assertJsonPath('data.inactive_count', 1);
    }
}
