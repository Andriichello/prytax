<?php

namespace PryTax\DataLifecycle\Backup\Http\Controllers;

use Carbon\CarbonImmutable;
use Core\Http\Controllers\CrudController;
use Core\Http\Responses\ApiResponse;
use Core\Models\AuditLog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Http\Requests\ShowExportRequest;
use PryTax\DataLifecycle\Backup\Http\Requests\StoreExportRequest;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunCollection;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunResource;
use PryTax\DataLifecycle\Backup\Internal\ArchiveStore;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupDownload;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\DataLifecycle\Backup\Policies\BackupRunPolicy;
use PryTax\DataLifecycle\Backup\Repositories\BackupRunRepository;

/**
 * Class ExportController.
 *
 * Period exports of a profile (backups.md §2.7, §4.7): `{tenant}/exports`, registered under the routes of a tenant,
 * whose parameter names it (`profiles/{profile}`, found in the organization by the app's middleware). An export is a
 * run of one profile, which isn't restorable and is kept a day; its status, and once it is done, a download link,
 * which is logged.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Controllers
 */
class ExportController extends CrudController
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
        'export' => StoreExportRequest::class,
        'exportStatus' => ShowExportRequest::class,
    ];

    /**
     * Route parameter of the tenant, under whose routes the controller's are.
     *
     * @var string
     */
    public static string $tenantParameter = 'profile';

    /**
     * ExportController constructor.
     *
     * @param BackupRunPolicy $policy
     * @param BackupRunRepository $repository
     * @param BackupRuns $runs
     * @param ArchiveStore $store
     */
    public function __construct(
        BackupRunPolicy $policy,
        BackupRunRepository $repository,
        protected BackupRuns $runs,
        protected ArchiveStore $store
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Register the routes, inside a group of a tenant's routes.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::post('exports', [static::class, 'export'])->name('exports.store');
        Route::get('exports/{export}', [static::class, 'exportStatus'])
            ->where('export', '[0-9A-Za-z]{26}')
            ->middleware('throttle:30,60')
            ->name('exports.show');
    }

    /**
     * Handle the `export` operation: start the export of the period.
     *
     * @param StoreExportRequest $request
     *
     * @return ApiResponse
     */
    protected function handleExport(StoreExportRequest $request): ApiResponse
    {
        $run = $this->runs->export(
            $request->orgId(),
            $this->tenantOf($request),
            CarbonImmutable::parse($request->validated('from')),
            CarbonImmutable::parse($request->validated('to')),
            $request->user()?->getKey(),
        );

        return $this->asResourceResponse($run->fresh())->setMessage('Started')->setStatus(202);
    }

    /**
     * Handle the `exportStatus` operation: the export's status, with a download link once it is done.
     *
     * @param ShowExportRequest $request
     *
     * @return ApiResponse
     */
    protected function handleExportStatus(ShowExportRequest $request): ApiResponse
    {
        $tenant = $this->tenantOf($request);

        /** @var BackupRun $run */
        $run = BackupRun::query()
            ->where('kind', BackupKind::Export)
            ->whereJsonContains('profile_ids', $tenant)
            ->findOrFail($request->route('export'));

        /** @var Backup|null $backup */
        $backup = $run->backups()->where('profile_id', $tenant)->first();
        $data = ['id' => $run->id, 'status' => $run->status->value, 'scope' => $run->scope];

        if ($backup?->hasArchive() && ($backup->expires_at?->isFuture() ?? true)) {
            $expiresAt = now()->addMinutes((int) config('data-lifecycle.backups.url_lifetime'));

            BackupDownload::query()->create([
                'backup_id' => $backup->id,
                'run_id' => $run->id,
                'user_id' => $request->user()?->getKey(),
                'kind' => 'export',
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]);
            AuditLog::record('backup_downloaded', null, [
                'name' => $backup->getMorphClass() . '.downloaded',
                'metadata' => ['backup_id' => $backup->id, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()],
            ]);

            $data['url'] = $this->store->url((string) $backup->disk, (string) $backup->object_path, str_replace('.zip', '_' . $run->scope['from'] . '_' . $run->scope['to'] . '.zip', (string) $backup->file_name), $expiresAt);
            $data['expires_at'] = $expiresAt->toIso8601String();
        }

        return ApiResponse::ok(['data' => $data]);
    }

    /**
     * Get id of the request's tenant.
     *
     * @param StoreExportRequest|ShowExportRequest $request
     *
     * @return int
     */
    protected function tenantOf(StoreExportRequest|ShowExportRequest $request): int
    {
        $tenant = $request->route(static::$tenantParameter);

        return (int) (is_object($tenant) ? $tenant->getKey() : $tenant);
    }
}
