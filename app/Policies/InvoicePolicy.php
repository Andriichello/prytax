<?php

namespace PryTax\Policies;

use Core\Models\BaseModel;
use Core\Models\User;
use Core\Policies\CrudPolicy;
use PryTax\Enums\InvoiceStatus;
use PryTax\Models\Invoice;

/**
 * Class InvoicePolicy.
 *
 * Authorizes the requests of invoices. It mirrors the invoice's lock (design.md §4), so the API answers before the
 * model refuses: only a draft is edited or deleted, a draft is frozen, a ready one returns to its draft or is sent,
 * and a ready or sent one is marked paid or cancelled. The `{ability} models.invoices` permission comes first.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Policies
 */
class InvoicePolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Invoice::class;

    /**
     * Determine whether the user can update the invoice: a draft, or the notes of a frozen one, which InvoiceWriter
     * limits it to.
     *
     * @param User|null $user
     * @param Invoice $model
     *
     * @return bool
     */
    public function update(mixed $user, mixed $model): bool
    {
        return parent::update($user, $model);
    }

    /**
     * Determine whether the user can delete the invoice: a draft only.
     *
     * @param User|null $user
     * @param Invoice $model
     *
     * @return bool
     */
    public function delete(mixed $user, mixed $model): bool
    {
        return parent::delete($user, $model) && $model->status === InvoiceStatus::Draft;
    }

    /**
     * Determine whether the user can freeze the invoice: a draft.
     *
     * @param User|null $user
     * @param Invoice $model
     *
     * @return bool
     */
    public function freeze(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user) && $model->status === InvoiceStatus::Draft;
    }

    /**
     * Determine whether the user can return the invoice to its draft: a ready one.
     *
     * @param User|null $user
     * @param Invoice $model
     *
     * @return bool
     */
    public function unfreeze(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user) && $model->status === InvoiceStatus::Ready;
    }

    /**
     * Determine whether the user can mark the invoice as sent: a ready one.
     *
     * @param User|null $user
     * @param Invoice $model
     *
     * @return bool
     */
    public function send(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user) && $model->status === InvoiceStatus::Ready;
    }

    /**
     * Determine whether the user can mark the invoice as paid: a ready or sent one.
     *
     * @param User|null $user
     * @param Invoice $model
     *
     * @return bool
     */
    public function pay(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user) && in_array($model->status, [InvoiceStatus::Ready, InvoiceStatus::Sent], true);
    }

    /**
     * Determine whether the user can cancel the invoice: a ready or sent one.
     *
     * @param User|null $user
     * @param Invoice $model
     *
     * @return bool
     */
    public function cancel(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user) && in_array($model->status, [InvoiceStatus::Ready, InvoiceStatus::Sent], true);
    }
}
