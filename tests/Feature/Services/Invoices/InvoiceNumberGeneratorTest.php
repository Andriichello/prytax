<?php

namespace Tests\Feature\Services\Invoices;

use Core\Models\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PryTax\Models\Account;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Services\Invoices\InvoiceFreezer;
use PryTax\Services\Invoices\InvoiceNumberGenerator;
use PryTax\Services\Invoices\InvoiceWriter;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoiceNumberGeneratorTest.
 *
 * Invoice numbers (design.md §5): `{abbreviation}-{period end}-{n}` with the client's own counter by default, or
 * without the abbreviation and with one counter per ФОП, previewed without being allocated, rebuilt with the same
 * `{n}` when a re-frozen draft's date or client's abbreviation changed, newly allocated when the yearly scope changed,
 * and never touched when the user typed them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class InvoiceNumberGeneratorTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that by default a number starts with the client's abbreviation and counts the client's invoices only.
     *
     * @return void
     */
    public function testNumbersInvoicesWithTheClientsAbbreviationAndCounterByDefault(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $nordwave = $this->createClientWithContract($profile, ['abbreviation' => 'NW']);
        $this->inProfile($profile, fn () => Sequence::setNext($profile, 'invoices:client:' . $brightline[0]->id, 13));

        $first = $this->freezeFor($profile, $account, $brightline, ['period_start' => '2026-10-01', 'period_end' => '2026-10-14']);
        $other = $this->freezeFor($profile, $account, $nordwave);

        $this->assertSame('BL-2026-10-14-13', $first->number);
        $this->assertSame('NW-2026-09-30-1', $other->number);
        $this->assertSame('client:' . $nordwave[0]->id, $other->sequence_scope_key);
    }

    /**
     * Test that without the prefix and with one counter per ФОП, two invoices of the same period end differ.
     *
     * @return void
     */
    public function testNumbersTheInvoicesOfAllClientsWithOneCounterWithoutThePrefix(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings([
            'invoice.numbering.prefix' => 'none',
            'invoice.numbering.scope' => 'global',
        ]));
        $account = $this->createAccount($profile);

        $first = $this->createFrozenInvoice($profile, $account);
        $second = $this->createFrozenInvoice($profile, $account);

        $this->assertNotSame($first->client_id, $second->client_id);
        $this->assertSame('2026-09-30-1', $first->number);
        $this->assertSame('2026-09-30-2', $second->number);
        $this->assertSame('global', $second->sequence_scope_key);
    }

    /**
     * Test that without the prefix, the clients' own counters can arrive at one number, which the freeze refuses.
     *
     * @return void
     */
    public function testRefusesToFreezeANumberAnotherClientsCounterGaveWithoutThePrefix(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.numbering.prefix' => 'none']));
        $account = $this->createAccount($profile);
        $this->createFrozenInvoice($profile, $account);

        $this->expectExceptionObject(ValidationException::withMessages([
            'number' => ['Another invoice has the number 2026-09-30-1 already.'],
        ]));

        $this->createFrozenInvoice($profile, $account);
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
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $this->freezeFor($profile, $account, $brightline);

        $draft = $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $brightline[0]->id,
            'receiving_account_id' => $account->id,
            'period_start' => '2026-10-15',
            'period_end' => '2026-10-31',
            'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']],
        ]));

        $preview = fn () => $this->inProfile($profile, fn () => app(InvoiceNumberGenerator::class)->preview($profile, $draft));

        $this->assertSame('BL-2026-10-31-2', $preview());
        $this->assertSame('BL-2026-10-31-2', $preview());
        $this->assertSame(2, $this->inProfile($profile, fn () => Sequence::peek($profile, 'invoices:client:' . $brightline[0]->id)));
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
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $invoice = $this->freezeFor($profile, $account, $brightline, ['period_start' => '2026-10-01', 'period_end' => '2026-10-14']);

        $refrozen = $this->inProfile($profile, function () use ($profile, $invoice) {
            $draft = app(InvoiceFreezer::class)->unfreeze($profile, $invoice);
            $draft = app(InvoiceWriter::class)->update($profile, $draft, ['period_end' => '2026-10-15']);

            return app(InvoiceFreezer::class)->freeze($profile, $draft);
        });

        $this->assertSame('BL-2026-10-15-1', $refrozen->number);
        $this->assertSame(2, $this->inProfile($profile, fn () => Sequence::peek($profile, 'invoices:client:' . $brightline[0]->id)));
    }

    /**
     * Test that a changed abbreviation leaves the numbers given as they are, while a re-frozen draft takes it with
     * its `{n}`.
     *
     * @return void
     */
    public function testRebuildsTheAbbreviationOfAReFrozenNumberAndKeepsTheNumbersGiven(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $given = $this->freezeFor($profile, $account, $brightline, ['period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
        $invoice = $this->freezeFor($profile, $account, $brightline);

        $refrozen = $this->inProfile($profile, function () use ($profile, $brightline, $invoice) {
            $brightline[0]->update(['abbreviation' => 'BRL']);
            $draft = app(InvoiceFreezer::class)->unfreeze($profile, $invoice);

            return app(InvoiceFreezer::class)->freeze($profile, $draft);
        });

        $this->assertSame('BRL-2026-09-30-2', $refrozen->number);
        $this->assertSame('BL-2026-08-31-1', $this->inProfile($profile, fn () => Invoice::query()->findOrFail($given->id)->number));
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
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $invoice = $this->freezeFor($profile, $account, $brightline, ['period_start' => '2026-12-01', 'period_end' => '2026-12-31']);
        $original = $invoice->number;

        $refrozen = $this->inProfile($profile, function () use ($profile, $invoice) {
            $draft = app(InvoiceFreezer::class)->unfreeze($profile, $invoice);
            $draft = app(InvoiceWriter::class)->update($profile, $draft, ['period_start' => '2027-01-01', 'period_end' => '2027-01-31']);

            return app(InvoiceFreezer::class)->freeze($profile, $draft);
        });

        $this->assertSame('BL-2026-12-31-1', $original);
        $this->assertSame('BL-2027-01-31-1', $refrozen->number);
        $this->assertSame('client:' . $brightline[0]->id . ':2027', $refrozen->sequence_scope_key);
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
        $this->assertSame(1, $this->inProfile($profile, fn () => Sequence::peek($profile, 'invoices:client:' . $invoice->client_id)));
    }

    /**
     * Test that the `sequence` strategy numbers `{n}` after the abbreviation, without the date.
     *
     * @return void
     */
    public function testNumbersWithoutTheDateWithTheSequenceStrategy(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.numbering.strategy' => 'sequence']));
        $account = $this->createAccount($profile);
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);

        $first = $this->freezeFor($profile, $account, $brightline);
        $second = $this->freezeFor($profile, $account, $brightline);

        $this->assertSame(['BL-1', 'BL-2'], [$first->number, $second->number]);
    }

    /**
     * Test that a draft numbered with the counter the user chose previews and freezes with it, and the counter goes on
     * after it.
     *
     * @return void
     */
    public function testNumbersADraftWithTheCounterTheUserChoseAndContinuesAfterIt(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);

        $draft = $this->draftFor($profile, $account, $brightline, ['sequence_number' => 20]);
        $preview = $this->inProfile($profile, fn () => app(InvoiceNumberGenerator::class)->preview($profile, $draft));
        $chosen = $this->inProfile($profile, fn () => app(InvoiceFreezer::class)->freeze($profile, $draft));
        $next = $this->freezeFor($profile, $account, $brightline);

        $this->assertSame('BL-2026-10-31-20', $preview);
        $this->assertSame('BL-2026-10-31-20', $chosen->number);
        $this->assertSame('BL-2026-09-30-21', $next->number);
    }

    /**
     * Test that a counter another invoice holds can't be chosen, and that the next number skips one a draft chose.
     *
     * @return void
     */
    public function testRefusesACounterAnotherInvoiceHoldsAndSkipsOneADraftChose(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $this->freezeFor($profile, $account, $brightline);
        $this->draftFor($profile, $account, $brightline, ['sequence_number' => 2]);

        try {
            $this->draftFor($profile, $account, $brightline, ['sequence_number' => 1]);
            $this->fail('A counter another invoice holds was chosen.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Another invoice has the counter 1 already.'], $exception->errors()['sequence_number']);
        }

        $this->assertSame('BL-2026-09-30-3', $this->freezeFor($profile, $account, $brightline)->number);
    }

    /**
     * Test that null gives a chosen counter back to the generator, while a draft frozen before keeps the one it got.
     *
     * @return void
     */
    public function testGivesAChosenCounterBackButKeepsTheOneOfADraftFrozenBefore(): void
    {
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $brightline = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $frozen = $this->freezeFor($profile, $account, $brightline);

        [$chosen, $unfrozen] = $this->inProfile($profile, function () use ($profile, $account, $brightline, $frozen) {
            $writer = app(InvoiceWriter::class);
            $chosen = $writer->update($profile, $this->draftFor($profile, $account, $brightline, ['sequence_number' => 20]), ['sequence_number' => null]);
            $unfrozen = $writer->update($profile, app(InvoiceFreezer::class)->unfreeze($profile, $frozen), ['sequence_number' => null]);

            return [$chosen->append('number_preview')->toArray(), $unfrozen->append('number_preview')->toArray()];
        });

        $this->assertNull($chosen['sequence_number']);
        $this->assertSame('BL-2026-10-31-2', $chosen['number_preview']);
        $this->assertSame(1, $unfrozen['sequence_number']);
        $this->assertSame('BL-2026-09-30-1', $unfrozen['number_preview']);
    }

    /**
     * Create a draft of the client and contract for the second half of October 2026.
     *
     * @param Profile $profile
     * @param Account $account
     * @param array $client The client and its contract, as `createClientWithContract()` returns them.
     * @param array $data
     *
     * @return Invoice
     */
    private function draftFor(Profile $profile, Account $account, array $client, array $data = []): Invoice
    {
        return $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client[0]->id,
            'contract_id' => $client[1]->id,
            'receiving_account_id' => $account->id,
            'period_start' => '2026-10-15',
            'period_end' => '2026-10-31',
            'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']],
            ...$data,
        ]));
    }

    /**
     * Freeze an invoice of the client and contract, with the defaults of `createFrozenInvoice()` otherwise.
     *
     * @param Profile $profile
     * @param Account $account
     * @param array $client The client and its contract, as `createClientWithContract()` returns them.
     * @param array $data
     *
     * @return Invoice
     */
    private function freezeFor(Profile $profile, Account $account, array $client, array $data = []): Invoice
    {
        return $this->createFrozenInvoice($profile, $account, ['client_id' => $client[0]->id, 'contract_id' => $client[1]->id, ...$data]);
    }
}
