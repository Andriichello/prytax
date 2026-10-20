<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\PaymentResource;
use PryTax\Models\Payment;
use PryTax\Services\Payments\PaymentRecorder;

/**
 * Class PaymentRepository.
 *
 * Repository of the receipts of a profile, newest first, filtered by their quarter and month too. PaymentRecorder
 * writes them, with their snapshots and the period lock.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class PaymentRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Payment::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = PaymentResource::class;

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
    protected array $allowedFilters = ['period_year', 'period_quarter', 'period_month', 'uah_amount', 'source_transfer_id'];

    /**
     * Sorts, besides the fillable columns.
     *
     * @var array
     */
    protected array $allowedSorts = ['uah_amount'];

    /**
     * PaymentRepository constructor.
     *
     * @param PaymentRecorder $recorder
     */
    public function __construct(
        protected PaymentRecorder $recorder
    ) {}

    /**
     * Record a receipt.
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
     * Update the receipt.
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
     * Delete the receipt, with the request's correction note for a locked quarter.
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
