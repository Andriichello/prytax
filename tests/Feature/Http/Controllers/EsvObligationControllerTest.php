<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Enums\EsvStatus;
use PryTax\Models\EsvObligation;
use PryTax\Services\Taxes\EsvObligationScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class EsvObligationControllerTest.
 *
 * Months of ЄСВ (design.md §3, §8.3): they are read, not marked exempt by hand in the «Minimal» phase, and an exempt
 * month, which a tax rule makes voluntary, is owed by its quarter no more.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class EsvObligationControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a month can't be marked exempt through the API.
     *
     * @return void
     */
    public function testReturns405WhenAMonthIsMarkedExempt(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->inProfile($profile, fn () => app(EsvObligationScheduler::class)->ensureYear($profile, 2026));
        $february = $this->inProfile($profile, fn () => EsvObligation::query()->where('year', 2026)->where('month', 2)->firstOrFail());

        $this->patchJson($this->profilePath($profile, "esv-obligations/{$february->id}"), ['status' => 'exempt'])
            ->assertMethodNotAllowed();

        $this->assertSame(EsvStatus::Due, $february->fresh()->status);
    }

    /**
     * Test that an exempt month is left out of what its quarter owes.
     *
     * @return void
     */
    public function testLeavesAnExemptMonthOutOfTheQuartersEsv(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, function () use ($profile) {
            app(EsvObligationScheduler::class)->ensureYear($profile, 2026);
            EsvObligation::query()->where('year', 2026)->where('month', 2)->update(['status' => EsvStatus::Exempt->value]);
        });

        $esv = $this->inProfile($profile, fn () => app(TaxLiabilityCalculator::class)->forQuarter($profile, 2026, 1)['esv']);

        $this->assertSame('3804.68', $esv['owed']);
        $this->assertSame(2, $esv['months']);
    }
}
