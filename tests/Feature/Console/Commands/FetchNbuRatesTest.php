<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PryTax\Models\Payment;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class FetchNbuRatesTest.
 *
 * The nightly NBU rates (design.md §10): the day's rate of every currency, which active profiles use, and a retry of
 * the days of receipts saved at a manual rate.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class FetchNbuRatesTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the day's rates of the profiles' currencies are cached, and the NBU is asked again for the day of a
     * receipt saved at a manual rate.
     *
     * @return void
     */
    public function testCachesTheDaysRatesAndRetriesTheDaysOfManualRates(): void
    {
        Http::fake([
            'bank.gov.ua/*date=20261020*' => Http::response('[{"rate":42.0870,"cc":"USD"}]'),
            'bank.gov.ua/*date=20260707*' => Http::response('[{"rate":41.8710,"cc":"USD"}]'),
        ]);
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id,
            'received_to_account_id' => $account->id,
            'received_at' => '2026-07-07',
            'nbu_rate' => '40.0000',
            'nbu_rate_date' => '2026-07-07',
            'rate_is_manual' => true,
        ]));

        $this->artisan('prytax:fetch-rates', ['--date' => '2026-10-20'])->assertSuccessful();

        $this->assertDatabaseHas('exchange_rates', ['currency' => 'USD', 'rate_date' => '2026-10-20', 'rate' => '42.0870']);
        $this->assertDatabaseHas('exchange_rates', ['currency' => 'USD', 'rate_date' => '2026-07-07', 'rate' => '41.8710']);
        Http::assertSentCount(2);
    }
}
