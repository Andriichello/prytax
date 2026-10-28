<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunCollection;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunResource;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Policies\BackupRunPolicy;
use PryTax\DataLifecycle\Backup\Repositories\BackupRunRepository;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;
use PryTax\DataLifecycle\Retention\Models\RetentionRun;
use PryTax\Models\Profile;

/**
 * Class DeletedProfileController.
 *
 * «Видалені ФОП» of Резервні копії (backups.md §4.7, §4.8): the organization's profiles, which are deleted and whose
 * purge is planned, each with the day it was deleted, the day its data and backups go (the last job of its retention
 * run) and the automatic backup taken at its deletion. Owners and accountants read it, as they read the backups;
 * owners restore a profile through `POST profiles/{profile}/restore`, which cancels the purge.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class DeletedProfileController extends CrudController
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
        'deleted' => IndexRequest::class,
    ];

    /**
     * DeletedProfileController constructor.
     *
     * @param BackupRunPolicy $policy
     * @param BackupRunRepository $repository
     */
    public function __construct(BackupRunPolicy $policy, BackupRunRepository $repository)
    {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `deleted` operation: the deleted profiles in their grace, newest first.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleDeleted(IndexRequest $request): ApiResponse
    {
        $profiles = Profile::query()->onlyTrashed()->orderByDesc('deleted_at')->get();
        $runs = RetentionRun::query()->withoutGlobalScopes()
            ->where('subject_type', 'profile')
            ->whereIn('subject_id', $profiles->modelKeys())
            ->whereIn('status', [RetentionStatus::Pending, RetentionStatus::Dispatched, RetentionStatus::Running])
            ->with('jobs')
            ->get()
            ->keyBy('subject_id');

        $data = $profiles
            ->filter(fn (Profile $profile) => $runs->has($profile->id))
            ->map(function (Profile $profile) use ($runs) {
                $final = Backup::query()
                    ->where('profile_id', $profile->id)
                    ->where('tier', BackupKind::Automatic)
                    ->where('status', BackupStatus::Completed)
                    ->whereHas('run', fn ($query) => $query->where('reason', 'profile_deleted'))
                    ->orderByDesc('created_at')
                    ->first();

                return [
                    'id' => $profile->id,
                    'full_name' => $profile->full_name,
                    'short_name' => $profile->short_name,
                    'initials' => $profile->initials,
                    'deleted_at' => $profile->deleted_at?->toIso8601String(),
                    'purge_at' => $runs[$profile->id]->jobs->max('due_at')?->toIso8601String(),
                    'final_backup_id' => $final?->id,
                    'final_backup_bytes' => $final?->bytes,
                ];
            })
            ->values();

        return ApiResponse::ok(['data' => $data]);
    }
}
