<?php

namespace Tests\Feature\Models;

use Core\Exceptions\RecordLockedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\InvoiceItem;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoiceItemTest.
 *
 * The items of a frozen invoice are locked with it (design.md §4), also in code, which runs outside of a profile,
 * such as a console command or an import.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Models
 */
class InvoiceItemTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an item of a frozen invoice can't change outside of a profile.
     *
     * @return void
     */
    public function testLocksTheItemsOfAFrozenInvoiceOutsideOfAProfile(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));
        $item = InvoiceItem::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->expectException(RecordLockedException::class);

        $item->update(['amount' => '1.00']);
    }
}
