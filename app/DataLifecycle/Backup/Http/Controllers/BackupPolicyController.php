<?php

namespace PryTax\DataLifecycle\Backup\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Responses\ApiResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PryTax\DataLifecycle\Backup\Http\Requests\ConfigureBackupPolicyRequest;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupPolicyCollection;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupPolicyResource;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Policies\BackupPolicyPolicy;
use PryTax\DataLifecycle\Backup\Repositories\BackupPolicyRepository;

/**
 * Class BackupPolicyController.
 *
 * The organization's backup policy (backups.md §2.2, §4.7): `organization/backup-policy`, the defaults while the
 * organization has none; owners change it, which plans its next run.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Controllers
 */
class BackupPolicyController extends CrudController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = BackupPolicyResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = BackupPolicyCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'policy' => IndexRequest::class,
        'configure' => ConfigureBackupPolicyRequest::class,
    ];

    /**
     * BackupPolicyController constructor.
     *
     * @param BackupPolicyPolicy $policy
     * @param BackupPolicyRepository $repository
     */
    public function __construct(BackupPolicyPolicy $policy, BackupPolicyRepository $repository)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Register the routes, inside a group of the organization's routes.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::get('backup-policy', [static::class, 'policy'])->name('backup-policy.show');
        Route::put('backup-policy', [static::class, 'configure'])->name('backup-policy.update');
    }

    /**
     * Handle the `policy` operation: the organization's policy.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handlePolicy(IndexRequest $request): ApiResponse
    {
        return $this->asResourceResponse(BackupPolicy::of($request->orgId()));
    }

    /**
     * Handle the `configure` operation: change the policy, and plan its next run.
     *
     * @param ConfigureBackupPolicyRequest $request
     *
     * @return ApiResponse
     */
    protected function handleConfigure(ConfigureBackupPolicyRequest $request): ApiResponse
    {
        $policy = DB::transaction(function () use ($request) {
            $policy = BackupPolicy::of($request->orgId());
            $policy->fill(Arr::except($request->validated(), 'profile_ids'));
            $policy->forceFill(['updated_by_user_id' => $request->user()?->getKey()]);
            $policy->next_run_at = $policy->enabled ? $policy->nextRunAfter(now()) : null;
            $policy->save();

            if ($request->has('profile_ids')) {
                $policy->syncProfileIds($request->validated('profile_ids', []));
            }

            return $policy;
        });

        return $this->asResourceResponse($policy->fresh())->setMessage('Updated');
    }
}
