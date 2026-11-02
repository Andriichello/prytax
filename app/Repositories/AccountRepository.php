<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PryTax\Http\Resources\AccountResource;
use PryTax\Models\Account;

/**
 * Class AccountRepository.
 *
 * Repository of the accounts of a profile, in their order on the screen. A new account is saved with its first set of
 * requisites (`requisites`), when it names one, in one transaction.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class AccountRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Account::class;

    /**
     * Whether a write of the repository's records can change the profile's notifications, so they are synced after.
     *
     * @var bool
     */
    protected bool $syncsAlerts = true;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = AccountResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['sort_order' => 'asc', 'id' => 'asc'];

    /**
     * AccountRepository constructor.
     *
     * @param AccountRequisiteSetRepository $requisiteSets
     */
    public function __construct(
        protected AccountRequisiteSetRepository $requisiteSets
    ) {}

    /**
     * Create the account, with its first set of requisites, when the attributes have any.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        $requisites = array_filter((array) Arr::pull($attributes, 'requisites'), fn ($value) => filled($value));

        return DB::transaction(function () use ($attributes, $parents, $requisites) {
            $account = parent::create($attributes, $parents);

            if ($requisites !== []) {
                $this->requisiteSets->create($requisites, [...Arr::only($parents, ['profile']), 'account' => $account]);
            }

            return $account;
        });
    }
}
