<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\CrudRequest;
use PryTax\Models\Profile;

/**
 * Class TenantController.
 *
 * A CRUD controller of a profile's records, under `profiles/{profile}/…`: the profile is the outermost parent, so it
 * is found within what the user may list (404 otherwise), the operations see only its records, and new records
 * belong to it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
abstract class TenantController extends CrudController
{
    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile'];

    /**
     * Get the profile of the request.
     *
     * @param CrudRequest $request
     *
     * @return Profile
     */
    protected function profileOf(CrudRequest $request): Profile
    {
        /** @var Profile $profile */
        $profile = $request->getParent('profile');

        return $profile;
    }
}
