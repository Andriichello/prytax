<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use Illuminate\Support\Facades\DB;
use PryTax\Helpers\CurrentProfile;
use PryTax\Http\Resources\ProfileResource;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\EsvObligationScheduler;

/**
 * Class ProfileRepository.
 *
 * Repository of the profiles (ФОП) of the organization. A new profile gets its tax periods and ЄСВ months at once.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class ProfileRepository extends CrudRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Profile::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = ProfileResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['is_active' => 'desc', 'full_name' => 'asc'];

    /**
     * ProfileRepository constructor.
     *
     * @param DeadlineScheduler $deadlines
     * @param EsvObligationScheduler $esv
     */
    public function __construct(
        protected DeadlineScheduler $deadlines,
        protected EsvObligationScheduler $esv
    ) {}

    /**
     * Create a profile with its tax periods and ЄСВ months.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        return DB::transaction(function () use ($attributes, $parents) {
            /** @var Profile $profile */
            $profile = parent::create($attributes, $parents);

            CurrentProfile::run($profile, function () use ($profile) {
                $this->deadlines->ensureCurrentAndNext($profile);
                $this->esv->ensureYear($profile, now()->year);
            });

            return $profile;
        });
    }
}
