<?php

namespace Tests\Feature\Services\Alerts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\Alert;
use PryTax\Models\Payment;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Payments\PaymentRecorder;
use PryTax\Services\Payments\RefundRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AlertSyncTest.
 *
 * Notifications follow their conditions (design.md §9): one row per dedupe key while the condition holds, resolved,
 * never deleted, when it clears, with the users' reads kept, and their texts written in every language of the app.
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
            ...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $account->id,
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

    /**
     * Test that a notification, whose condition holds again, is news again: dated anew, and unread by everyone.
     *
     * @return void
     */
    public function testMakesAReturningNotificationUnreadAgain(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $user = $this->userOf($profile->organization, 'viewer');
        $account = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-19' => '42.0150', '2026-10-21' => '42.0000']]);
        $invoice = $this->createFrozenInvoice($profile, $account, ['due_at' => '2026-10-19']);
        $this->inProfile($profile, fn () => app(AlertSync::class)->sync($profile));
        $alert = $this->inProfile($profile, fn () => Alert::query()->where('dedupe_key', "overdue:{$invoice->id}")->firstOrFail());
        DB::table('alert_reads')->insert(['alert_id' => $alert->id, 'user_id' => $user->id, 'read_at' => now()]);
        $receipt = $this->recordReceipt($profile, $invoice, $account, ['received_at' => '2026-10-19']);
        $this->travelTo('2026-10-22 10:00:00');

        $this->inProfile($profile, fn () => app(RefundRecorder::class)->record($profile, $receipt, [
            'amount' => '100.00', 'from_account_id' => $account->id, 'refunded_at' => '2026-10-21',
        ]));

        $this->assertNull($alert->fresh()->resolved_at);
        $this->assertSame('2026-10-22', $alert->fresh()->created_at->toDateString());
        $this->assertDatabaseMissing('alert_reads', ['alert_id' => $alert->id]);
    }

    /**
     * Test that an account's limit is reported from exactly 80 %, not from a percent rounded up to it, once per
     * calendar window.
     *
     * @return void
     */
    public function testReportsALimitFromExactlyEightyPercentPerWindow(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD', 'limit_amount' => '1000.00', 'limit_period' => 'year']);
        $receipt = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $payoneer->id, 'amount' => '799.99', 'received_at' => '2026-10-05',
        ]));
        $keys = fn () => $this->inProfile($profile, function () use ($profile) {
            app(AlertSync::class)->sync($profile);

            return Alert::query()->whereNull('resolved_at')->where('type', 'limit')->pluck('dedupe_key')->all();
        });

        $below = $keys();
        $receipt->forceFill(['received_amount' => '800.00'])->save();

        $this->assertSame([], $below);
        $this->assertSame(["limit:{$payoneer->id}:80:2026-01-01"], $keys());
    }

    /**
     * Test that a notification's texts are written in English too, and its Ukrainian ones in its columns.
     *
     * @return void
     */
    public function testWritesTheTextsOfANotificationInEveryLanguage(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile, ['name' => 'Brightline Labs LLC', 'abbreviation' => 'BL']);
        $invoice = $this->createFrozenInvoice($profile, $account, [
            'client_id' => $client->id, 'contract_id' => $contract->id,
            'period_start' => '2026-09-16', 'period_end' => '2026-10-15', 'due_at' => '2026-10-19',
        ]);

        $alert = $this->inProfile($profile, function () use ($profile, $invoice) {
            app(AlertSync::class)->sync($profile);

            return Alert::query()->where('dedupe_key', "overdue:{$invoice->id}")->firstOrFail();
        });

        // A JSON column keeps no order of keys
        $this->assertEquals([
            'title' => 'BL-2026-10-15-1 · Brightline Labs LLC',
            'body' => "Payment was due on 19.10.2026. Unpaid: 3\u{202F}892.50 USD.",
            'action_label' => 'Open the invoice',
            'context' => 'Overdue invoice',
        ], $alert->texts['en']);
        $this->assertEquals(
            ['title' => $alert->title, 'body' => $alert->body, 'action_label' => $alert->action_label, 'context' => $alert->payload['context']],
            $alert->texts['uk'],
        );
        $this->assertSame('Інвойс прострочено', $alert->payload['context']);
    }

    /**
     * Test that unpaid ЄСВ past its deadline is a warning (design.md §9), while ЄП past its deadline is an error, each
     * one notice at the latest quarter due, which carries the earlier quarters' debt.
     *
     * @return void
     */
    public function testWarnsOfUnpaidEsvPastItsDeadline(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $this->createAccount($profile)->id, 'received_at' => '2026-05-05',
        ]));

        $alerts = $this->inProfile($profile, function () use ($profile) {
            app(AlertSync::class)->sync($profile);

            return Alert::query()->whereNull('resolved_at')->where('type', 'deadline')->get()->keyBy(fn (Alert $alert) => $alert->payload['kind'] . ':' . $alert->title);
        });

        $this->assertSame('warning', $alerts->get('esv:ЄСВ за III кв. 2026')->severity->value);
        $this->assertSame('До сплати 17' . "\u{202F}" . '121.06 ₴ — строк минув 19.10.2026.', $alerts->get('esv:ЄСВ за III кв. 2026')->body);
        $this->assertSame('ЄСВ for Q3 2026', $alerts->get('esv:ЄСВ за III кв. 2026')->texts['en']['title']);
        $this->assertSame('Due 17' . "\u{202F}" . '121.06 ₴ — the deadline passed on 19.10.2026.', $alerts->get('esv:ЄСВ за III кв. 2026')->texts['en']['body']);
        $this->assertSame('error', $alerts->get('tax:ЄП і ВЗ за II кв. 2026')->severity->value);
        $this->assertCount(1, $alerts->filter(fn (Alert $alert) => $alert->payload['kind'] === 'esv'));
    }

    /**
     * Test that, in December, the missing tax rules of the next year are reported.
     *
     * @return void
     */
    public function testReportsTheMissingTaxRulesOfTheNextYearInDecember(): void
    {
        $this->travelTo('2026-12-01 10:00:00');
        $profile = $this->createProfile();

        $this->inProfile($profile, fn () => app(AlertSync::class)->sync($profile));

        $this->assertDatabaseHas('alerts', ['profile_id' => $profile->id, 'dedupe_key' => 'tax_rules:2027', 'resolved_at' => null]);
    }

    /**
     * Test that a contract's end, changed through the API, is reported without waiting for the daily sync.
     *
     * @return void
     */
    public function testSyncsAfterAContractChanges(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile);
        [$client, $contract] = $this->createClientWithContract($profile);

        $this->patchJson($this->profilePath($profile, "clients/{$client->id}/contracts/{$contract->id}"), ['ends_at' => '2026-11-10'])->assertOk();

        $this->assertDatabaseHas('alerts', ['profile_id' => $profile->id, 'dedupe_key' => "contract:{$contract->id}:ending", 'resolved_at' => null]);
    }
}
