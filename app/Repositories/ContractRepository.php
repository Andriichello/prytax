<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PryTax\Http\Resources\ContractResource;
use PryTax\Models\Contract;

/**
 * Class ContractRepository.
 *
 * Repository of the contracts of clients, which saves a contract with its hourly rates: given rates replace the
 * contract's own.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class ContractRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Contract::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = ContractResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['is_active' => 'desc', 'started_at' => 'desc'];

    /**
     * Create a contract with its rates.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        return DB::transaction(function () use ($attributes, $parents) {
            /** @var Contract $contract */
            $contract = parent::create(Arr::except($attributes, 'rates'), $parents);
            $this->syncRates($contract, $attributes['rates'] ?? []);

            return $contract->load('rates');
        });
    }

    /**
     * Update the contract, and replace its rates, when they are given.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        return DB::transaction(function () use ($model, $attributes) {
            $updated = empty(Arr::except($attributes, 'rates')) || parent::update($model, Arr::except($attributes, 'rates'));

            if (array_key_exists('rates', $attributes)) {
                $this->syncRates($model, $attributes['rates'] ?? []);
            }

            return $updated;
        });
    }

    /**
     * Replace the contract's rates.
     *
     * @param Contract $contract
     * @param array<int, array{charge_type_id?: int|null, effective_from: string, hourly_rate: string}> $rates
     *
     * @return void
     */
    protected function syncRates(Contract $contract, array $rates): void
    {
        $contract->rates()->delete();

        foreach ($rates as $rate) {
            $contract->rates()->create(Arr::only($rate, ['charge_type_id', 'effective_from', 'hourly_rate']));
        }
    }
}
