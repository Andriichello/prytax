<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\SettingController;
use Core\Repositories\SettingRepository;
use PryTax\Models\Profile;
use PryTax\Policies\ProfilePolicy;

/**
 * Class ProfileSettingController.
 *
 * Settings of a profile: `profiles/{profile}/settings` (numbering, money formats, the taxable base, defaults of the
 * taxes, repatriation and deadline warnings). Reading needs `view`, changing `configure` of the profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ProfileSettingController extends SettingController
{
    /**
     * Class of the model, whose settings the controller handles.
     *
     * @var string
     */
    protected string $settable = Profile::class;

    /**
     * Route parameter of the model.
     *
     * @var string
     */
    protected string $parameter = 'profile';

    /**
     * ProfileSettingController constructor.
     *
     * @param ProfilePolicy $policy
     * @param SettingRepository $repository
     */
    public function __construct(ProfilePolicy $policy, SettingRepository $repository)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Get the abilities on the profile, which the abilities of the requests need: reading needs `view`, changing
     * `configure`, which accountants have without the `update` of the profile's identity.
     *
     * @return array<string, string>
     */
    protected function parentAbilities(): array
    {
        return [
            'viewAny' => 'view',
            'view' => 'view',
            'create' => 'configure',
            'update' => 'configure',
            'delete' => 'configure',
        ];
    }
}
