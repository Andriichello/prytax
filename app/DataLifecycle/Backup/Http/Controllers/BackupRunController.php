<?php

namespace PryTax\DataLifecycle\Backup\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Requests\Crud\StoreRequest;
use Core\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Http\Requests\StoreBackupRunRequest;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunCollection;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunResource;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\DataLifecycle\Backup\Policies\BackupRunPolicy;
use PryTax\DataLifecycle\Backup\Repositories\BackupRunRepository;

/**
 * Class BackupRunController.
 *
 * Runs of the organization's backup (backups.md §4.7): `organization/backup-runs`, newest first, each with its
 * backups (`?include=backups`); a manual run of every profile or of the listed ones, five a day at most, which answers
 * 202 with the run, as its job does the work.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Controllers
 */
class BackupRunController extends CrudController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = BackupRunResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = BackupRunCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreBackupRunRequest::class,
    ];

    /**
     * BackupRunController constructor.
     *
     * @param BackupRunPolicy $policy
     * @param BackupRunRepository $repository
     * @param BackupRuns $runs
     */
    public function __construct(BackupRunPolicy $policy, BackupRunRepository $repository, protected BackupRuns $runs)
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
        Route::get('backup-runs', [static::class, 'index'])->name('backup-runs.index');
        Route::post('backup-runs', [static::class, 'store'])->name('backup-runs.store');
        Route::get('backup-runs/{id}', [static::class, 'show'])->name('backup-runs.show');
    }

    /**
     * Handle the `store` operation: start a manual run.
     *
     * @param StoreRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStore(StoreRequest $request): ApiResponse
    {
        /** @var StoreBackupRunRequest $request */
        $today = BackupRun::query()->where('kind', BackupKind::Manual)->where('created_at', '>=', now()->startOfDay())->count();

        $limit = (int) config('data-lifecycle.backups.manual_per_day');

        if ($today >= $limit) {
            return ApiResponse::make([], 429, __('The organization has made :count manual backups today already.', ['count' => $limit]));
        }

        $run = $this->runs->manual($request->orgId(), $request->profileIds(), $request->user()?->getKey());

        return $this->asResourceResponse($run->fresh())->setMessage('Started')->setStatus(202);
    }
}
