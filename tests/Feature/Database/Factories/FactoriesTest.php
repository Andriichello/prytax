<?php

namespace Tests\Feature\Database\Factories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\AccountMovement;
use PryTax\Models\AccountOpeningBalance;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Alert;
use PryTax\Models\ChargeType;
use PryTax\Models\ContractRate;
use PryTax\Models\EsvObligation;
use PryTax\Models\ExchangeRate;
use PryTax\Models\Holiday;
use PryTax\Models\IdempotencyKey;
use PryTax\Models\InvoiceItem;
use PryTax\Models\Project;
use PryTax\Models\Refund;
use PryTax\Models\TaxOpeningBalance;
use PryTax\Models\TaxPayment;
use PryTax\Models\TaxPaymentRequisite;
use PryTax\Models\TaxPeriod;
use PryTax\Models\TaxRule;
use PryTax\Models\TransferAllocation;
use Tests\TestCase;

/**
 * Class FactoriesTest.
 *
 * Every factory makes a record, which the database and the models' rules accept, with its parents created for it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Database\Factories
 */
class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The models, whose factories are tested.
     *
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function models(): array
    {
        return collect([
            AccountMovement::class, AccountOpeningBalance::class, AccountTransfer::class, Alert::class, ChargeType::class,
            ContractRate::class, EsvObligation::class, ExchangeRate::class, Holiday::class, IdempotencyKey::class,
            InvoiceItem::class, Project::class, Refund::class, TaxOpeningBalance::class, TaxPayment::class,
            TaxPaymentRequisite::class, TaxPeriod::class, TaxRule::class, TransferAllocation::class,
        ])->mapWithKeys(fn (string $model) => [class_basename($model) => [$model]])->all();
    }

    /**
     * Test that the model's factory creates a record.
     *
     * @param class-string<Model> $model
     *
     * @return void
     */
    #[DataProvider('models')]
    public function testCreatesARecord(string $model): void
    {
        $record = $model::factory()->create();

        $this->assertModelExists($record);
    }
}
