<?php

namespace PryTax\Policies;

use Core\Models\BaseModel;
use Core\Policies\CrudPolicy;
use PryTax\Models\Client;

/**
 * Class ClientPolicy.
 *
 * Authorizes the requests of the clients of a profile: the `{ability} models.*` permission of the user's role first (see the
 * roles of config/api-core.php), and then CrudPolicy's rules.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Policies
 */
class ClientPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Client::class;
}
