<?php

namespace Tests\Feature\Services\Taxes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\TaxType;
use PryTax\Models\Alert;
use PryTax\Models\EsvObligation;
use PryTax\Models\TaxPeriod;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Payments\PaymentRecorder;
use PryTax\Services\Reports\EsvTable;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\EsvObligationScheduler;
use PryTax\Services\Taxes\TaxReconciler;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxTrackingTest.
 *
 * The quarters, in which a profile's obligations are tracked: from its registration or its first record to its
 * closure. A newly registered ФОП has no deadlines before its registration, reading another year creates nothing
 * there, and a year without tax rules owes nothing rather than last year's figures.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Taxes
 */
class TaxTrackingTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a ФОП registered on 1 September gets the periods and ЄСВ months from then on, and no notices of the
     * quarters before.
     *
     * @return void
     */
    public function testTracksANewlyRegisteredFopFromItsRegistration(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile(null, ['registered_at' => '2026-09-01', 'created_at' => now()]);

        $this->inProfile($profile, function () use ($profile) {
            app(DeadlineScheduler::class)->ensureCurrentAndNext($profile);
            app(EsvObligationScheduler::class)->ensureTracked($profile);
            app(AlertSync::class)->sync($profile);
        });

        $periods = TaxPeriod::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->orderBy('year')->orderBy('quarter')
            ->get()->map(fn (TaxPeriod $period) => "{$period->year}-{$period->quarter}")->all();
        $months = EsvObligation::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->pluck('month')->all();

        $this->assertSame(['2026-3', '2026-4', '2027-1'], $periods);
        $this->assertSame([9, 10, 11, 12], $months);
        $this->assertFalse(Alert::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('title', 'like', 'Декларація за I%')->exists());
    }

    /**
     * Test that reading a year before the tracking creates neither its ЄСВ months nor its periods, so the balances
     * of the tracked years don't change by what was read.
     *
     * @return void
     */
    public function testCreatesNothingOutsideTheTrackingByReading(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $balance = fn () => $this->inProfile($profile, fn () => app(TaxReconciler::class)->forYear($profile, TaxType::SocialContribution, 2026)['balance']);
        $before = $balance();

        $table = $this->inProfile($profile, fn () => app(EsvTable::class)->forYear($profile, 2025));

        $this->assertSame('0.00', $table['accrued']);
        $this->assertSame($before, $balance());
        $this->assertSame('-22828.08', $before);
        $this->assertFalse(EsvObligation::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('year', 2025)->exists());
        $this->assertFalse(TaxPeriod::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('year', 2025)->exists());
    }

    /**
     * Test that a year without tax rules owes no ЄСВ and takes no receipts, rather than last year's figures.
     *
     * @return void
     */
    public function testTakesNoRulesOfAYearWithoutThem(): void
    {
        $this->travelTo('2026-12-20 10:00:00');
        $profile = $this->createProfile();
        $account = $this->createAccount($profile, ['currency' => 'UAH', 'kind' => 'fop_bank', 'name' => 'ПриватБанк ФОП']);

        $this->inProfile($profile, fn () => app(EsvObligationScheduler::class)->ensureTracked($profile));

        $this->assertFalse(EsvObligation::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('year', 2027)->exists());

        try {
            $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
                'kind' => 'other', 'currency' => 'UAH', 'amount' => '1000.00', 'received_to_account_id' => $account->id,
                'received_at' => '2027-01-05',
            ]));
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['received_at' => ['No tax rules are in effect on 05.01.2027.']], $exception->errors());
        }
    }
}
