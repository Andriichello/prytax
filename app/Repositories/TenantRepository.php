<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PryTax\Helpers\CurrentProfile;
use PryTax\Http\Middleware\ResolveProfile;
use PryTax\Models\Profile;

/**
 * Class TenantRepository.
 *
 * Repository of a model of a profile. Records, which other records reference, can't be deleted (every foreign key
 * of the domain is RESTRICT, design.md §10): such a delete answers 422 with a hint to deactivate the record, rather
 * than 500.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
abstract class TenantRepository extends CrudRepository
{
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
            return parent::delete($model);
        } catch (QueryException $exception) {
            // 1451: a foreign key restricts the delete
            if (($exception->errorInfo[1] ?? null) === 1451) {
                throw ValidationException::withMessages([
                    'id' => [__('Other records reference it, so it can\'t be deleted. Deactivate it instead.')],
                ]);
            }

            throw $exception;
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
