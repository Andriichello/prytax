<?php

namespace Tests\Feature\Services\Invoices;

use Core\Models\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Services\Invoices\InvoiceFreezer;
use PryTax\Services\Invoices\InvoiceNumberGenerator;
use PryTax\Services\Invoices\InvoiceWriter;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoiceNumberGeneratorTest.
 *
 * Invoice numbers (design.md §5): `{period end}-{n}` with one counter per ФОП by default, so numbers of two clients
 * never collide, or one per client, previewed without being allocated, rebuilt with the same `{n}` when a re-frozen
 * draft's date moved, newly allocated when the yearly scope changed, and never touched when the user typed them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class InvoiceNumberGeneratorTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that by default the invoices of all clients share one counter, so two of the same period end differ.
     *
     * @return void
     */
    public function testNumbersTheInvoicesOfAllClientsWithOneCounterByDefault(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);

        $first = $this->createFrozenInvoice($profile, $account);
        $second = $this->createFrozenInvoice($profile, $account);

        $this->assertNotSame($first->client_id, $second->client_id);
        $this->assertSame('2026-09-30-1', $first->number);
        $this->assertSame('2026-09-30-2', $second->number);
        $this->assertSame('global', $second->sequence_scope_key);
    }

    /**
     * Test that the counters can be per client.
     *
     * @return void
     */
    public function testNumbersInvoicesByThePeriodEndAndTheClientsCounter(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.numbering.scope' => 'per_client']));
        $account = $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile);
        $this->inProfile($profile, fn () => Sequence::setNext($profile, 'invoices:client:' . $client->id, 13));

        $first = $this->createFrozenInvoice($profile, $account, [
            'client_id' => $client->id, 'contract_id' => $contract->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
        ]);
        $other = $this->createFrozenInvoice($profile, $account);

        $this->assertSame('2026-10-14-13', $first->number);
        $this->assertSame('2026-09-30-1', $other->number);
        $this->assertSame('client:' . $other->client_id, $other->sequence_scope_key);
    }

    /**
     * Test that the preview of a draft is the number it would get, without taking it.
     *
     * @return void
     */
    public function testPreviewsTheNextNumberWithoutAllocatingIt(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $invoice = $this->createFrozenInvoice($profile, $account);

        $draft = $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $invoice->client_id,
            'receiving_account_id' => $account->id,
            'period_start' => '2026-10-15',
            'period_end' => '2026-10-31',
            'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']],
        ]));

        $preview = fn () => $this->inProfile($profile, fn () => app(InvoiceNumberGenerator::class)->preview($profile, $draft));

        $this->assertSame('2026-10-31-2', $preview());
        $this->assertSame('2026-10-31-2', $preview());
        $this->assertSame(2, $this->inProfile($profile, fn () => Sequence::peek($profile, 'invoices:global')));
    }

    /**
     * Test that a re-frozen draft, whose period end moved, keeps its `{n}` with the new date.
     *
     * @return void
     */
    public function testRebuildsTheDateOfAReFrozenNumberWithTheSameCounter(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $invoice = $this->createFrozenInvoice($profile, $account, ['period_start' => '2026-10-01', 'period_end' => '2026-10-14']);

        $refrozen = $this->inProfile($profile, function () use ($profile, $invoice) {
            $draft = app(InvoiceFreezer::class)->unfreeze($profile, $invoice);
            $draft = app(InvoiceWriter::class)->update($profile, $draft, ['period_end' => '2026-10-15']);

            return app(InvoiceFreezer::class)->freeze($profile, $draft);
        });

        $this->assertSame('2026-10-15-1', $refrozen->number);
        $this->assertSame(2, $this->inProfile($profile, fn () => Sequence::peek($profile, 'invoices:global')));
    }

    /**
     * Test that with a yearly reset a draft, which moved into another year, gets the next number of that year.
     *
     * @return void
     */
    public function testAllocatesANewNumberWhenTheYearlyScopeChanged(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.numbering.reset' => 'yearly']));
        $account = $this->createAccount($profile);
        $invoice = $this->createFrozenInvoice($profile, $account, ['period_start' => '2026-12-01', 'period_end' => '2026-12-31']);
        $original = $invoice->number;

        $refrozen = $this->inProfile($profile, function () use ($profile, $invoice) {
            $draft = app(InvoiceFreezer::class)->unfreeze($profile, $invoice);
            $draft = app(InvoiceWriter::class)->update($profile, $draft, ['period_start' => '2027-01-01', 'period_end' => '2027-01-31']);

            return app(InvoiceFreezer::class)->freeze($profile, $draft);
        });

        $this->assertSame('2026-12-31-1', $original);
        $this->assertSame('2027-01-31-1', $refrozen->number);
        $this->assertSame('global:2027', $refrozen->sequence_scope_key);
    }

    /**
     * Test that a number the user typed is kept as it is, and takes nothing from the counter.
     *
     * @return void
     */
    public function testKeepsANumberTheUserTyped(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);

        $invoice = $this->createFrozenInvoice($profile, $account, ['number' => 'INV-77']);

        $this->assertSame('INV-77', $invoice->number);
        $this->assertNull($invoice->sequence_number);
        $this->assertSame(1, $this->inProfile($profile, fn () => Sequence::peek($profile, 'invoices:global')));
    }

    /**
     * Test that the `sequence` strategy with one counter per profile numbers `{n}` across clients.
     *
     * @return void
     */
    public function testNumbersAcrossClientsWithAGlobalSequence(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings([
            'invoice.numbering.strategy' => 'sequence',
            'invoice.numbering.scope' => 'global',
        ]));
        $account = $this->createAccount($profile);

        $first = $this->createFrozenInvoice($profile, $account);
        $second = $this->createFrozenInvoice($profile, $account);

        $this->assertSame(['1', '2'], [$first->number, $second->number]);
        $this->assertNotSame($first->client_id, $second->client_id);
    }
}
