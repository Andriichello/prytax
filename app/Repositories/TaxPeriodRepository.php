<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Enums\DeclarationStatus;
use PryTax\Http\Resources\TaxPeriodResource;
use PryTax\Models\TaxPeriod;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class TaxPeriodRepository.
 *
 * Repository of the tax periods (quarters) of a profile, newest first. A declaration marked submitted or accepted
 * locks its quarter; one returned to none, filled or rejected unlocks it; corrected keeps the lock (design.md §8.3).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class TaxPeriodRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = TaxPeriod::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = TaxPeriodResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['year' => 'desc', 'quarter' => 'desc'];

    /**
     * TaxPeriodRepository constructor.
     *
     * @param AlertSync $alerts
     */
    public function __construct(
        protected AlertSync $alerts
    ) {}

    /**
     * Update the tax period, locking or unlocking it by its declaration status.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        /** @var TaxPeriod $model */
        $model->fill($attributes);

        if ($model->isDirty('declaration_status')) {
            $status = $model->declaration_status;

            if ($status->locks()) {
                $model->locked_at ??= now();
                $model->declaration_submitted_at ??= now();
            } elseif ($status !== DeclarationStatus::Corrected) {
                $model->locked_at = null;
            }
        }

        $saved = $model->save();

        $this->alerts->syncAfterCommit($this->profile());

        return $saved;
    }
}
