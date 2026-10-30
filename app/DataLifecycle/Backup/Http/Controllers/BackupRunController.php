<?php

namespace PryTax\DataLifecycle\Backup\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Requests\Crud\StoreRequest;
use Core\Http\Responses\ApiResponse;
use Core\Models\AuditLog;
use Core\Models\Organization;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Http\Controllers\Traits\LimitsDownloads;
use PryTax\DataLifecycle\Backup\Http\Requests\BundleRunRequest;
use PryTax\DataLifecycle\Backup\Http\Requests\StoreBackupRunRequest;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunCollection;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunResource;
use PryTax\DataLifecycle\Backup\Internal\ArchiveStore;
use PryTax\DataLifecycle\Backup\Internal\BundleBuilder;
use PryTax\DataLifecycle\Backup\Jobs\BuildOrganizationBundle;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupDownload;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\DataLifecycle\Backup\Policies\BackupRunPolicy;
use PryTax\DataLifecycle\Backup\Repositories\BackupRunRepository;

/**
 * Class BackupRunController.
 *
 * Runs of the organization's backup (backups.md §4.7): `organization/backup-runs`, newest first, each with its
 * backups (`?include=backups`); a manual run of every profile or of the listed ones, five a day at most, which answers
 * 202 with the run, as its job does the work; the organization's bundle of a run, built on demand (202) and then
 * downloaded through a signed link, which is logged.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Controllers
 */
class BackupRunController extends CrudController
{
    use LimitsDownloads;

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
        'bundle' => BundleRunRequest::class,
        'bundleStatus' => BundleRunRequest::class,
        'status' => IndexRequest::class,
    ];

    /**
     * BackupRunController constructor.
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
     * Register the routes, inside a group of the organization's routes.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::get('backup-status', [static::class, 'status'])->name('backup-status');
        Route::get('backup-runs', [static::class, 'index'])->name('backup-runs.index');
        Route::post('backup-runs', [static::class, 'store'])->name('backup-runs.store');
        Route::get('backup-runs/{id}', [static::class, 'show'])->name('backup-runs.show');
        Route::post('backup-runs/{id}/bundle', [static::class, 'bundle'])->name('backup-runs.bundle');
        Route::get('backup-runs/{id}/bundle', [static::class, 'bundleStatus'])->name('backup-runs.bundle.show');
    }

    /**
     * Handle the `status` operation: what the status card of «Резервні копії» shows (backups.md §4.7, §4.8) — the
     * latest run (not an export) with its backups, the schedule (on or off, the next run, the organization's time of
     * day), the size of the newest completed archive of every profile summed, and the manual runs left today.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStatus(IndexRequest $request): ApiResponse
    {
        $organizationId = $request->orgId();
        $policy = BackupPolicy::of($organizationId);
        $latest = BackupRun::query()
            ->where('kind', '!=', BackupKind::Export)
            ->with('backups')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $newest = Backup::query()
            ->where('status', BackupStatus::Completed)
            ->where('tier', '!=', BackupKind::Export)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'profile_id', 'bytes', 'created_at'])
            ->unique('profile_id');
        $today = BackupRun::query()->where('kind', BackupKind::Manual)->where('created_at', '>=', now()->startOfDay())->count();

        return ApiResponse::ok(['data' => [
            'policy' => [
                'enabled' => $policy->enabled,
                'frequency' => $policy->frequency,
                'next_run_at' => $policy->enabled ? ($policy->next_run_at ?? $policy->nextRunAfter(now()))->toIso8601String() : null,
                'time_of_day' => $policy->nextRunAfter(now())->format('H:i'),
                'retention_monthly' => $policy->retention_monthly,
            ],
            'latest_run' => $latest ? (new BackupRunResource($latest))->toArray($request) : null,
            'last_completed_at' => $newest->max('created_at')?->toIso8601String(),
            'profiles' => $newest->count(),
            'bytes' => (int) $newest->sum('bytes'),
            'manual_runs_left' => max(0, (int) config('data-lifecycle.backups.manual_per_day') - $today),
        ]]);
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

    /**
     * Handle the `bundle` operation: build the run's bundle, unless it is ready already.
     *
     * @param BundleRunRequest $request
     *
     * @return ApiResponse
     */
    protected function handleBundle(BundleRunRequest $request): ApiResponse
    {
        /** @var BackupRun $run */
        $run = $this->builder($request)->findOrFail($request->getId());

        if (!$this->bundleIsReady($run) && $run->bundle_status !== 'building') {
            $run->forceFill(['bundle_status' => 'building'])->save();
            BuildOrganizationBundle::dispatch($run->id);
        }

        return ApiResponse::make($this->bundleOf($request, $run->fresh()), 202, 'Building');
    }

    /**
     * Handle the `bundleStatus` operation: the bundle's status, and a download link of a ready one, logged.
     *
     * @param BundleRunRequest $request
     *
     * @return ApiResponse
     */
    protected function handleBundleStatus(BundleRunRequest $request): ApiResponse
    {
        /** @var BackupRun $run */
        $run = $this->builder($request)->findOrFail($request->getId());

        if ($this->bundleIsReady($run) && ($refusal = $this->refuseDownload($request))) {
            return $refusal;
        }

        return ApiResponse::ok($this->bundleOf($request, $run, link: true));
    }

    /**
     * Get the bundle's status, with a download link of a ready one when asked.
     *
     * @param BundleRunRequest $request
     * @param BackupRun $run
     * @param bool $link
     *
     * @return array{data: array{status: string|null, url?: string, expires_at?: string}}
     */
    protected function bundleOf(BundleRunRequest $request, BackupRun $run, bool $link = false): array
    {
        if (!$this->bundleIsReady($run)) {
            return ['data' => ['status' => $run->bundle_status === 'ready' ? null : $run->bundle_status]];
        }

        if (!$link) {
            return ['data' => ['status' => 'ready']];
        }

        $expiresAt = now()->addMinutes((int) config('data-lifecycle.backups.url_lifetime'));
        $organization = Organization::query()->findOrFail($run->organization_id);

        BackupDownload::query()->create([
            'run_id' => $run->id,
            'user_id' => $request->user()?->getKey(),
            'kind' => 'bundle',
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);
        AuditLog::record('backup_downloaded', null, [
            'name' => $run->getMorphClass() . '.downloaded',
            'metadata' => ['run_id' => $run->id, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()],
        ]);

        return ['data' => [
            'status' => 'ready',
            'url' => $this->store->url((string) $run->bundle_disk, (string) $run->bundle_path, BundleBuilder::fileName($run, $organization->slug), $expiresAt),
            'expires_at' => $expiresAt->toIso8601String(),
        ]];
    }

    /**
     * Determine if the run's bundle is built and not expired.
     *
     * @param BackupRun $run
     *
     * @return bool
     */
    protected function bundleIsReady(BackupRun $run): bool
    {
        return $run->bundle_status === 'ready' && $run->bundle_path !== null && ($run->bundle_expires_at?->isFuture() ?? false);
    }
}
