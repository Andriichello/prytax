<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\TaxPaymentResource;
use PryTax\Models\TaxPayment;
use PryTax\Services\Taxes\TaxPaymentRecorder;

/**
 * Class TaxPaymentRepository.
 *
 * Repository of the tax payments of a profile, newest first, which TaxPaymentRecorder writes with their periods.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class TaxPaymentRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = TaxPayment::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = TaxPaymentResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['paid_at' => 'desc', 'id' => 'desc'];

    /**
     * TaxPaymentRepository constructor.
     *
     * @param TaxPaymentRecorder $recorder
     */
    public function __construct(
        protected TaxPaymentRecorder $recorder
    ) {}

    /**
     * Record a tax payment.
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
     * Update the tax payment.
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
     * Delete the tax payment.
     *
     * @param BaseModel $model
     *
     * @return bool
     */
    public function delete(BaseModel $model): bool
    {
        $this->recorder->delete($this->profile(), $model);

        return true;
    }
}
