<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\SettingController;
use Core\Http\Requests\Crud\UpdateRequest;
use Core\Http\Responses\ApiResponse;
use Core\Models\AuditLog;
use Core\Repositories\SettingRepository;
use Illuminate\Support\Arr;
use PryTax\Models\Profile;
use PryTax\Policies\ProfilePolicy;
use PryTax\Services\Alerts\AlertSync;

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

    /**
     * Handle the `update` operation: record the changed settings in the audit log, as they change tax figures (the
     * taxable base, FX gains), and sync the profile's notifications, which follow them (the days of warning, the
     * repatriation rule, the money format of their texts).
     *
     * @param UpdateRequest $request
     *
     * @return ApiResponse
     */
    protected function handleUpdate(UpdateRequest $request): ApiResponse
    {
        /** @var Profile $profile */
        $profile = $this->settableOf($request);
        $before = $profile->settingValues();

        $response = parent::handleUpdate($request);

        $after = $profile->unsetRelation('settings')->settingValues();
        $changed = array_keys(array_filter($after, fn ($value, string $key) => $value !== ($before[$key] ?? null), ARRAY_FILTER_USE_BOTH));

        if (!empty($changed)) {
            AuditLog::record('settings_updated', $profile, [
                'changes' => ['old' => Arr::only($before, $changed), 'new' => Arr::only($after, $changed)],
            ]);
        }

        app(AlertSync::class)->syncAfterCommit($profile);

        return $response;
    }
}
