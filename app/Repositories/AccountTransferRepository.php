<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\AccountTransferResource;
use PryTax\Models\AccountTransfer;
use PryTax\Services\Transfers\TransferRecorder;

/**
 * Class AccountTransferRepository.
 *
 * Repository of the transfers between a profile's accounts, newest first, which TransferRecorder writes with their
 * allocations.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class AccountTransferRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = AccountTransfer::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = AccountTransferResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['received_at' => 'desc', 'id' => 'desc'];

    /**
     * Filters, besides the fillable columns.
     *
     * @var array
     */
    protected array $allowedFilters = ['currency_sent', 'currency_received'];

    /**
     * AccountTransferRepository constructor.
     *
     * @param TransferRecorder $recorder
     */
    public function __construct(
        protected TransferRecorder $recorder
    ) {}

    /**
     * Record a transfer with its allocations.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        return $this->recorder->record($parents['profile'] ?? $this->profile(), $attributes);
    }

    /**
     * Update the transfer.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        $this->recorder->update($this->profile(), $model, $attributes);

        return true;
    }

    /**
     * Delete the transfer, unless the money it brought moved on.
     *
     * @param BaseModel $model
     *
     * @return bool
     */
    public function delete(BaseModel $model): bool
    {
        $this->recorder->delete($this->profile(), $model, request()->input('correction_note'));

        return true;
    }
}
