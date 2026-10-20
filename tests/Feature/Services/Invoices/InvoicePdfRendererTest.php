<?php

namespace Tests\Feature\Services\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Helpers\MoneyFormatter;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoicePdfRendererTest.
 *
 * The invoice's document is rendered from its snapshot only (design.md §4, §10): in the money format, which was in
 * force when it was frozen, so a later change of the setting, of the profile or of the account renders the same.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class InvoicePdfRendererTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the template renders the snapshot's amounts in the snapshot's money format, after the setting and the
     * account changed.
     *
     * @return void
     */
    public function testRendersTheSnapshotInItsOwnMoneyFormat(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.money_format' => 'comma_dot']));
        $account = $this->createAccount($profile, ['bank_name' => 'Wise']);
        $invoice = $this->createFrozenInvoice($profile, $account);
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.money_format' => 'plain_dot']));
        $account->forceFill(['bank_name' => 'Renamed Bank'])->save();

        $snapshot = $invoice->fresh()->snapshot;
        $html = view('pdf.invoice', ['snapshot' => $snapshot, 'money' => MoneyFormatter::of($snapshot['money_format']), 'signature' => null])->render();

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame('comma_dot', $snapshot['money_format']);
        $this->assertStringContainsString('3,892.50', $html);
        $this->assertStringContainsString('Wise', $html);
        $this->assertStringNotContainsString('Renamed Bank', $html);
    }
}
