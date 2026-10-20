<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\EsvObligationResource;
use PryTax\Models\EsvObligation;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class EsvObligationRepository.
 *
 * Repository of the months of ЄСВ of a profile, which are generated, so only their status (due or exempt) and note
 * change.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class EsvObligationRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = EsvObligation::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = EsvObligationResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['year' => 'desc', 'month' => 'asc'];

    /**
     * Filters, besides the fillable columns.
     *
     * @var array
     */
    protected array $allowedFilters = ['year', 'month'];

    /**
     * EsvObligationRepository constructor.
     *
     * @param AlertSync $alerts
     */
    public function __construct(
        protected AlertSync $alerts
    ) {}

    /**
     * Update the month's status or note.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        $updated = parent::update($model, $attributes);

        $this->alerts->syncAfterCommit($this->profile());

        return $updated;
    }
}
