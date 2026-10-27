<?php

namespace PryTax\DataLifecycle\Backup\Http\Requests;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class BundleRunRequest.
 *
 * Builds, or downloads, the organization's bundle of a finished run (backups.md §4.5).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Requests
 */
class BundleRunRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'bundle';
}
