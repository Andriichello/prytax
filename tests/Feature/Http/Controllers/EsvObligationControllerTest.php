<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\EsvObligation;
use PryTax\Services\Taxes\EsvObligationScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class EsvObligationControllerTest.
 *
 * Months of ЄСВ (design.md §3, §8.1): a month, which a territorial exemption frees, is marked exempt, and the
 * quarter owes it no more.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class EsvObligationControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an exempt month is left out of what its quarter owes.
     *
     * @return void
     */
    public function testLeavesAnExemptMonthOutOfTheQuartersEsv(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $this->inProfile($profile, fn () => app(EsvObligationScheduler::class)->ensureYear($profile, 2026));
        $february = $this->inProfile($profile, fn () => EsvObligation::query()->where('year', 2026)->where('month', 2)->firstOrFail());

        $this->patchJson($this->profilePath($profile, "esv-obligations/{$february->id}"), ['status' => 'exempt', 'note' => 'Територіальне звільнення'])
            ->assertOk()
            ->assertJsonPath('data.status', 'exempt');

        $esv = $this->inProfile($profile, fn () => app(TaxLiabilityCalculator::class)->forQuarter($profile, 2026, 1)['esv']);
        $this->assertSame('3804.68', $esv['owed']);
        $this->assertSame(2, $esv['months']);
    }
}
