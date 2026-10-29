<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PryTax\Helpers\CurrentProfile;
use PryTax\Helpers\Sql;
use PryTax\Http\Middleware\ResolveProfile;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class TenantRepository.
 *
 * Repository of a model of a profile. Records, which other records reference, can't be deleted (every foreign key
 * of the domain is RESTRICT, design.md §10): such a delete answers 422 with a hint to deactivate the record, rather
 * than 500. A repository, whose records the notifications watch (contracts, accounts, opening balances), syncs them
 * after its writes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
abstract class TenantRepository extends CrudRepository
{
    /**
     * Whether a write of the repository's records can change the profile's notifications, so they are synced after.
     *
     * @var bool
     */
    protected bool $syncsAlerts = false;

    /**
     * Create the model, and sync the notifications, when they watch it.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        $model = parent::create($attributes, $parents);
        $this->syncAlerts();

        return $model;
    }

    /**
     * Update the model, and sync the notifications, when they watch it.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        $updated = parent::update($model, $attributes);
        $this->syncAlerts();

        return $updated;
    }

    /**
     * Delete the model, unless other records reference it.
     *
     * @param BaseModel $model
     *
     * @return bool
     * @throws ValidationException
     */
    public function delete(BaseModel $model): bool
    {
        try {
            $deleted = parent::delete($model);
            $this->syncAlerts();

            return $deleted;
        } catch (QueryException $exception) {
            if (Sql::restrictsDelete($exception)) {
                throw ValidationException::withMessages([
                    'id' => [__('Other records reference it, so it can\'t be deleted. Deactivate it instead.')],
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Sync the profile's notifications after the write, when they watch the repository's records.
     *
     * @return void
     */
    protected function syncAlerts(): void
    {
        $profile = $this->syncsAlerts ? $this->profile() : null;

        if ($profile !== null) {
            app(AlertSync::class)->syncAfterCommit($profile);
        }
    }

    /**
     * Get the profile of the current request.
     *
     * @return Profile
     */
    protected function profile(): Profile
    {
        $profile = request()->attributes->get(ResolveProfile::ATTRIBUTE);

        return $profile instanceof Profile ? $profile : CurrentProfile::get();
    }
}
