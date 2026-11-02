<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Http\Resources\AccountRequisiteSetResource;
use PryTax\Models\Account;
use PryTax\Models\AccountRequisiteSet;

/**
 * Class AccountRequisiteSetRepository.
 *
 * Repository of the sets of accounts' requisites (invoice.md §4): an account with sets has one default among its
 * active ones. Its first set is the default; making another one the default takes it from the one before; the default
 * isn't switched off or deleted while the account has other sets, until another one is made the default. A set, which
 * an invoice prints, stays (it's switched off instead); one only deleted drafts printed goes with their sections.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class AccountRequisiteSetRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = AccountRequisiteSet::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = AccountRequisiteSetResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['is_default' => 'desc', 'sort_order' => 'asc', 'id' => 'asc'];

    /**
     * Create the set, the default when its account has none yet, and after the account's other sets.
     *
     * @param array $attributes
     * @param array $parents
     *
     * @return BaseModel
     * @throws ValidationException
     */
    public function create(array $attributes, array $parents = []): BaseModel
    {
        /** @var Account $account */
        $account = $parents['account'];

        return DB::transaction(function () use ($attributes, $parents, $account) {
            $others = AccountRequisiteSet::query()->where('account_id', $account->getKey())->lockForUpdate()->get();
            $attributes['sort_order'] ??= (int) $others->max('sort_order') + ($others->isEmpty() ? 0 : 1);

            if ($others->doesntContain('is_default', true)) {
                $attributes['is_default'] = true;
            }

            $this->assertDefaultActive($attributes['is_default'] ?? false, $attributes['is_active'] ?? true);

            /** @var AccountRequisiteSet $set */
            $set = parent::create($attributes, $parents);
            $this->takeDefaultFromOthers($set);

            return $set;
        });
    }

    /**
     * Update the set: made the default, it takes it from the one before.
     *
     * @param BaseModel $model
     * @param array $attributes
     *
     * @return bool
     * @throws ValidationException
     */
    public function update(BaseModel $model, array $attributes): bool
    {
        /** @var AccountRequisiteSet $model */
        return DB::transaction(function () use ($model, $attributes) {
            $wasDefault = $model->is_default;
            $isDefault = (bool) ($attributes['is_default'] ?? $wasDefault);

            if ($wasDefault && !$isDefault) {
                throw ValidationException::withMessages([
                    'is_default' => [__('An account\'s sets have a default: make another set the default instead.')],
                ]);
            }

            $this->assertDefaultActive($isDefault, (bool) ($attributes['is_active'] ?? $model->is_active));

            $updated = parent::update($model, $attributes);
            $this->takeDefaultFromOthers($model);

            return $updated;
        });
    }

    /**
     * Delete the set, unless it's the default of an account with other sets, or an invoice prints it; the sections of
     * deleted drafts, which printed it, go with it (a deleted draft is no invoice).
     *
     * @param BaseModel $model
     *
     * @return bool
     * @throws ValidationException
     */
    public function delete(BaseModel $model): bool
    {
        /** @var AccountRequisiteSet $model */
        if ($model->is_default && AccountRequisiteSet::query()->where('account_id', $model->account_id)->whereKeyNot($model->getKey())->exists()) {
            throw ValidationException::withMessages([
                'id' => [__('The default set can\'t be deleted: make another set the default first.')],
            ]);
        }

        if ($model->paymentSections()->whereHas('invoice')->exists()) {
            throw ValidationException::withMessages([
                'id' => [__('Invoices print these requisites, so they can\'t be deleted: switch them off instead.')],
            ]);
        }

        return DB::transaction(function () use ($model) {
            $model->paymentSections()->whereHas('invoice', fn ($invoices) => $invoices->onlyTrashed())->get()->each->delete();

            return parent::delete($model);
        });
    }

    /**
     * Refuse a default set, which is switched off.
     *
     * @param bool $isDefault
     * @param bool $isActive
     *
     * @return void
     * @throws ValidationException
     */
    protected function assertDefaultActive(bool $isDefault, bool $isActive): void
    {
        if ($isDefault && !$isActive) {
            throw ValidationException::withMessages([
                'is_active' => [__('The default set can\'t be switched off: make another set the default first.')],
            ]);
        }
    }

    /**
     * Take the default from the account's other sets, when the set is the default.
     *
     * @param AccountRequisiteSet $set
     *
     * @return void
     */
    protected function takeDefaultFromOthers(AccountRequisiteSet $set): void
    {
        if (!$set->is_default) {
            return;
        }

        AccountRequisiteSet::query()
            ->where('account_id', $set->account_id)
            ->whereKeyNot($set->getKey())
            ->where('is_default', true)
            ->get()
            ->each(fn (AccountRequisiteSet $other) => $other->update(['is_default' => false]));
    }
}
