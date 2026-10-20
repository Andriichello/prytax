<?php

namespace Tests\Feature\Services\Taxes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Holiday;
use PryTax\Services\Taxes\DeadlineScheduler;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class DeadlineSchedulerTest.
 *
 * The deadlines of the quarters (design.md §1, §8.3): the declaration 40 days after the quarter, rolled to a
 * business day; ЄП and ВЗ 10 days after the un-rolled declaration date; ЄСВ by the 19th of the month after.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Taxes
 */
class DeadlineSchedulerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the declaration due on Sunday 10 May 2026 rolls to Monday, while ЄП stays due 20 May, and ЄСВ due on
     * Sunday 19 April rolls to the 20th.
     *
     * @return void
     */
    public function testRollsWeekendDeadlinesForwardFromTheirStatutoryDates(): void
    {
        $deadlines = app(DeadlineScheduler::class)->deadlines(2026, 1);

        $this->assertSame('2026-05-11', $deadlines['declaration_due_at']->toDateString());
        $this->assertSame('2026-05-20', $deadlines['tax_due_at']->toDateString());
        $this->assertSame('2026-04-20', $deadlines['esv_due_at']->toDateString());
    }

    /**
     * Test the deadlines of III кв. 2026, none of which falls on a weekend.
     *
     * @return void
     */
    public function testComputesTheDeadlinesOfAQuarter(): void
    {
        $deadlines = app(DeadlineScheduler::class)->deadlines(2026, 3);

        $this->assertSame('2026-11-09', $deadlines['declaration_due_at']->toDateString());
        $this->assertSame('2026-11-19', $deadlines['tax_due_at']->toDateString());
        $this->assertSame('2026-10-19', $deadlines['esv_due_at']->toDateString());
    }

    /**
     * Test that a holiday, once the table has holidays again, isn't a business day.
     *
     * @return void
     */
    public function testRollsADeadlineOverAHoliday(): void
    {
        Holiday::query()->create(['date' => '2026-11-09', 'name' => 'Test holiday']);

        $deadlines = app(DeadlineScheduler::class)->deadlines(2026, 3);

        $this->assertSame('2026-11-10', $deadlines['declaration_due_at']->toDateString());
    }

    /**
     * Test that a profile's tax period is created once, as annual for IV кв., with the profile's ЄСВ day.
     *
     * @return void
     */
    public function testCreatesATaxPeriodOnceWithTheProfilesEsvDay(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings(['taxes.esv_due_day' => 15]));

        $period = $this->inProfile($profile, fn () => app(DeadlineScheduler::class)->ensure($profile, 2026, 4));
        $again = $this->inProfile($profile, fn () => app(DeadlineScheduler::class)->ensure($profile, 2026, 4));

        $this->assertTrue($period->is($again));
        $this->assertTrue($period->is_annual);
        $this->assertSame('2027-01-15', $period->esv_due_at->toDateString());
        $this->assertDatabaseCount('tax_periods', 1);
    }

    /**
     * Test that a saved period's deadlines follow a change of the profile's ЄСВ day, and the rule's day is the
     * default.
     *
     * @return void
     */
    public function testMovesTheDeadlinesOfASavedPeriodWithTheEsvDay(): void
    {
        $profile = $this->createProfile();
        $period = $this->inProfile($profile, fn () => app(DeadlineScheduler::class)->ensure($profile, 2026, 3));
        $this->inProfile($profile, fn () => $profile->updateSettings(['taxes.esv_due_day' => 15]));

        $this->inProfile($profile, fn () => app(DeadlineScheduler::class)->ensure($profile, 2026, 3));

        $this->assertSame('2026-10-19', $period->esv_due_at->toDateString());
        $this->assertSame('2026-10-15', $period->fresh()->esv_due_at->toDateString());
    }
}
