<?php

namespace PryTax\Policies;

use Core\Models\BaseModel;
use Core\Policies\CrudPolicy;
use PryTax\Models\AccountRequisiteSet;

/**
 * Class AccountRequisiteSetPolicy.
 *
 * Authorizes the requests of the sets of accounts' requisites: the `{ability} models.*` permission of the user's role
 * first (see the roles of config/api-core.php), and then CrudPolicy's rules.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Policies
 */
class AccountRequisiteSetPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = AccountRequisiteSet::class;
}
