<?php

namespace Tests\Feature\Services\Alerts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\Alert;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Payments\PaymentRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AlertSyncTest.
 *
 * Notifications follow their conditions (design.md §9): one row per dedupe key while the condition holds, resolved,
 * never deleted, when it clears, with the users' reads kept.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Alerts
 */
class AlertSyncTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an overdue invoice is reported once, and resolved by the sync after the receipt, which covers it,
     * with its row and its read kept.
     *
     * @return void
     */
    public function testReportsAnOverdueInvoiceUntilAReceiptCoversIt(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $user = $this->userOf($profile->organization, 'viewer');
        $account = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-19' => '42.0150']]);
        $invoice = $this->createFrozenInvoice($profile, $account, [
            'period_start' => '2026-09-16', 'period_end' => '2026-10-15', 'due_at' => '2026-10-19',
        ]);
        $this->inProfile($profile, fn () => app(AlertSync::class)->sync($profile));
        $alert = $this->inProfile($profile, fn () => Alert::query()->where('dedupe_key', "overdue:{$invoice->id}")->firstOrFail());
        DB::table('alert_reads')->insert(['alert_id' => $alert->id, 'user_id' => $user->id, 'read_at' => now()]);

        $synced = $this->inProfile($profile, fn () => app(AlertSync::class)->sync($profile));

        $this->assertSame(['opened' => 0, 'resolved' => 0], $synced);
        $this->assertSame("Термін оплати минув 19.10.2026. Не оплачено 3\u{202F}892.50 USD.", $alert->body);

        $this->recordReceipt($profile, $invoice, $account, ['received_at' => '2026-10-19']);

        $this->assertNotNull($alert->fresh()->resolved_at);
        $this->assertModelExists($alert);
        $this->assertDatabaseHas('alert_reads', ['alert_id' => $alert->id, 'user_id' => $user->id]);
    }

    /**
     * Test that a receipt saved at a manual rate is reported, once the NBU published a different rate of its day,
     * and resolved by the update, which takes the NBU's rate.
     *
     * @return void
     */
    public function testReportsAManualRateUntilTheReceiptTakesTheNbus(): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response('Service Unavailable', 503)]);
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $payment = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
            'kind' => 'other', 'currency' => 'USD', 'amount' => '100.00', 'received_to_account_id' => $account->id,
            'received_at' => '2026-07-07', 'nbu_rate' => '40.0000',
        ]));
        $this->cacheRates(['USD' => ['2026-07-07' => '41.8710']]);

        $this->inProfile($profile, fn () => app(AlertSync::class)->sync($profile));
        $alert = $this->inProfile($profile, fn () => Alert::query()->where('dedupe_key', "manual_rate:payment:{$payment->id}")->first());

        $this->assertNotNull($alert);
        $this->assertSame('Курс 1 USD = 40.0000 ₴, а НБУ опублікував 41.8710 ₴. Оновіть запис, щоб він узяв курс НБУ.', $alert->body);
        $this->assertSame("/payments/{$payment->id}", $alert->action_route);

        $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $payment, []));

        $this->assertNotNull($alert->fresh()->resolved_at);
    }
}
