<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\DataLifecycle\Backup\BackupState;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\Models\Integration;
use PryTax\Models\Profile;

/**
 * Class GetBackupStatus.
 *
 * The state of the organization's backups, as Резервні копії tells it: the policy, the latest run, the newest copy of
 * each ФОП.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Get the state of the organization\'s backups: whether scheduled backups are on, how often and when next, the latest run (its status and times), when the newest backup of the ФОП completed, how many ФОП it covers and its size in bytes.')]
class GetBackupStatus extends ReadTool
{
    /**
     * The model, whose policy authorizes the read.
     *
     * @var class-string
     */
    protected string $model = BackupRun::class;

    /**
     * Whether the tool reads one ФОП.
     *
     * @var bool
     */
    protected bool $ofProfile = false;

    /**
     * Read the backups' state.
     *
     * @param array<string, mixed> $arguments
     * @param User $user
     * @param Integration $integration
     * @param Profile|null $profile
     *
     * @return array<string, mixed>
     */
    protected function read(array $arguments, User $user, Integration $integration, ?Profile $profile): array
    {
        $state = app(BackupState::class)->of((int) $user->organization_id);
        $run = $state['latest_run'];

        return [
            ...$state,
            'policy' => [...$state['policy'], 'frequency' => $state['policy']['frequency']?->value ?? $state['policy']['frequency']],
            'latest_run' => $run === null ? null : [
                'kind' => $run->kind?->value ?? $run->kind,
                'status' => $run->status?->value ?? $run->status,
                'created_at' => $run->created_at?->toIso8601String(),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'error' => $run->error,
            ],
        ];
    }
}
