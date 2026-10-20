<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use Illuminate\Support\Facades\DB;
use PryTax\Helpers\CurrentProfile;
use PryTax\Http\Resources\ProfileResource;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;
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
     * @param AlertSync $alerts
     */
    public function __construct(
        protected DeadlineScheduler $deadlines,
        protected EsvObligationScheduler $esv,
        protected AlertSync $alerts
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
                $this->esv->ensureTracked($profile);
            });

            return $profile;
        });
    }

    /**
     * Update the profile, and follow a change of its registration, closure or activity: its tax periods, ЄСВ months
     * and notifications (an inactive profile's are resolved).
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        /** @var Profile $model */
        $updated = parent::update($model, $attributes);

        if ($model->wasChanged(['registered_at', 'closed_at', 'is_active'])) {
            CurrentProfile::run($model, function () use ($model) {
                $this->deadlines->ensureCurrentAndNext($model);
                $this->esv->ensureTracked($model);
                $this->alerts->syncAfterCommit($model);
            });
        }

        return $updated;
    }
}
