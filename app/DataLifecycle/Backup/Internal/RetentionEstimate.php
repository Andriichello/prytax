<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use PryTax\DataLifecycle\Backup\Models\Backup;

/**
 * Class RetentionEstimate.
 *
 * How long a backup stays under its organization's current policy, as the screens show it («зберігається до …»,
 * backups.md §4.7): the pruner's estimate, computed once per organization for the request (a scoped binding).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
class RetentionEstimate
{
    /**
     * The estimates computed, by organization.
     *
     * @var array<int, array<string, array{until: string|null, reason: string}>>
     */
    private array $estimates = [];

    /**
     * RetentionEstimate constructor.
     *
     * @param Pruner $pruner
     */
    public function __construct(private readonly Pruner $pruner) {}

    /**
     * Get the estimate of a backup: the last day it stays (null until deleted) and why.
     *
     * @param Backup $backup
     *
     * @return array{until: string|null, reason: string|null}
     */
    public function of(Backup $backup): array
    {
        $estimates = $this->estimates[$backup->organization_id] ??= $this->pruner->keepUntil((int) $backup->organization_id);

        return $estimates[$backup->id] ?? ['until' => null, 'reason' => null];
    }
}
