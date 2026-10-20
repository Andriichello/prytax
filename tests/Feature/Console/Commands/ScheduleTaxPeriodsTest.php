<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\EsvObligation;
use PryTax\Models\TaxPeriod;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ScheduleTaxPeriodsTest.
 *
 * The daily tax periods (design.md §8.3): every active profile gets its tracked quarters up to the next one and its
 * ЄСВ months, each in its own context; an inactive one gets none.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class ScheduleTaxPeriodsTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that active profiles get their periods and ЄСВ months, and inactive ones don't.
     *
     * @return void
     */
    public function testSchedulesThePeriodsOfActiveProfiles(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $active = $this->createProfile();
        $inactive = $this->createProfile(null, ['is_active' => false, 'closed_at' => '2026-03-31']);

        $this->artisan('prytax:schedule-tax-periods')->assertSuccessful();

        $periods = fn ($profile) => TaxPeriod::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->count();
        $this->assertSame(5, $periods($active));
        $this->assertSame(12, EsvObligation::query()->withoutGlobalScopes()->where('profile_id', $active->id)->count());
        $this->assertSame(0, $periods($inactive));
    }
}
