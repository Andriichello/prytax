<?php

namespace PryTax\Policies;

use Core\Models\BaseModel;
use Core\Models\User;
use Core\Policies\CrudPolicy;
use PryTax\Enums\PaymentKind;
use PryTax\Models\Payment;

/**
 * Class PaymentPolicy.
 *
 * Authorizes the requests of receipts. A synthetic FX gain follows its transfer, so it is neither edited nor deleted
 * on its own; dismissing a receipt's repatriation notice is the `acknowledge` ability.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Policies
 */
class PaymentPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Payment::class;

    /**
     * Determine whether the user can update the receipt.
     *
     * @param User|null $user
     * @param Payment $model
     *
     * @return bool
     */
    public function update(mixed $user, mixed $model): bool
    {
        return parent::update($user, $model) && $model->kind !== PaymentKind::FxGain;
    }

    /**
     * Determine whether the user can delete the receipt.
     *
     * @param User|null $user
     * @param Payment $model
     *
     * @return bool
     */
    public function delete(mixed $user, mixed $model): bool
    {
        return parent::delete($user, $model) && $model->kind !== PaymentKind::FxGain;
    }

    /**
     * Determine whether the user can dismiss the receipt's repatriation notice.
     *
     * @param User|null $user
     * @param Payment $model
     *
     * @return bool
     */
    public function acknowledge(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user);
    }
}
