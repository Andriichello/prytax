<?php

namespace PryTax\Policies;

use Core\Models\BaseModel;
use Core\Models\User;
use Core\Policies\CrudPolicy;
use PryTax\Models\Alert;

/**
 * Class AlertPolicy.
 *
 * Authorizes the requests of a profile's notifications. Marking one as read is the user's own state, so every role
 * with the `read models.alerts` permission may, viewers included.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Policies
 */
class AlertPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Alert::class;

    /**
     * Determine whether the user can mark the notification as read.
     *
     * @param User|null $user
     * @param Alert $model
     *
     * @return bool
     */
    public function read(mixed $user, mixed $model): bool
    {
        return $user instanceof User;
    }
}
