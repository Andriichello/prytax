<?php

namespace Tests\Feature\Services\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PryTax\Services\Invoices\InvoiceWriter;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoiceWriterTest.
 *
 * Draft invoices (design.md §4): the contract's hourly rate prices hours of an invoice in the contract's currency
 * only, never a fixed item or an invoice in another currency.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class InvoiceWriterTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a fixed item and an item of an invoice in another currency than the contract's need their price.
     *
     * @return void
     */
    public function testPricesOnlyHoursInTheContractsCurrencyByItsRate(): void
    {
        $profile = $this->createProfile();
        $this->createAccount($profile);
        $euros = $this->createAccount($profile, ['name' => 'Wise EUR', 'currency' => 'EUR']);
        [$client, $contract] = $this->createClientWithContract($profile);
        $draft = fn (array $data) => $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client->id, 'contract_id' => $contract->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            ...$data,
        ]));

        $hours = $draft(['items' => [['quantity' => '10.00']]]);

        $this->assertSame('450.00', $hours->total);
        foreach ([
            'a fixed item' => ['items' => [['quantity' => '1', 'unit' => 'fixed']]],
            'an invoice in EUR' => ['currency' => 'EUR', 'receiving_account_id' => $euros->id, 'items' => [['quantity' => '10.00']]],
        ] as $case => $data) {
            try {
                $draft($data);
                $this->fail("No exception was thrown for $case.");
            } catch (ValidationException $exception) {
                $this->assertSame(
                    ['items.0.unit_price' => ["The price is required: the contract has no hourly rate in the invoice's currency."]],
                    $exception->errors(),
                    $case
                );
            }
        }
    }
}
