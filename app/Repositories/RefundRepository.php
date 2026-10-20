<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\RefundResource;
use PryTax\Models\Refund;
use PryTax\Services\Payments\RefundRecorder;

/**
 * Class RefundRepository.
 *
 * Repository of the refunds of receipts, which RefundRecorder writes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class RefundRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Refund::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = RefundResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['refunded_at' => 'desc', 'id' => 'desc'];

    /**
     * RefundRepository constructor.
     *
     * @param RefundRecorder $recorder
     */
    public function __construct(
        protected RefundRecorder $recorder
    ) {}

    /**
     * Record a refund of the parent receipt.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        return $this->recorder->record($parents['profile'] ?? $this->profile(), $parents['payment'], $attributes);
    }

    /**
     * Delete the refund, with the request's correction note for a locked quarter.
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
