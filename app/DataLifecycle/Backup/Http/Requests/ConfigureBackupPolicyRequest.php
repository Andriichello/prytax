<?php

namespace PryTax\DataLifecycle\Backup\Http\Requests;

use Core\Http\Requests\CrudRequest;
use Core\Http\Requests\Traits\WithObjectBody;
use Illuminate\Validation\Rule;
use PryTax\DataLifecycle\Backup\Enums\BackupFrequency;
use PryTax\DataLifecycle\Backup\Enums\BackupNotify;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Contracts\TenantRoot;

/**
 * Class ConfigureBackupPolicyRequest.
 *
 * Changes the organization's backup policy (backups.md §2.2), owners only: any of its settings, and the profiles of a
 * policy, which backs up selected ones.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Requests
 */
class ConfigureBackupPolicyRequest extends CrudRequest
{
    use WithObjectBody;

    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'configure';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $root = app(TenantRoot::class);

        return [
            'enabled' => ['sometimes', 'boolean'],
            'frequency' => ['sometimes', Rule::enum(BackupFrequency::class)],
            'weekday' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:7', 'required_if:frequency,weekly'],
            'month_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:28', 'required_if:frequency,monthly'],
            'retention_daily' => ['sometimes', 'integer', 'min:0', 'max:31'],
            'retention_weekly' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'retention_monthly' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'include_documents' => ['sometimes', 'boolean'],
            'include_audit_log' => ['sometimes', 'boolean'],
            'profiles_mode' => ['sometimes', Rule::in([BackupPolicy::ALL_PROFILES, BackupPolicy::SELECTED_PROFILES])],
            'profile_ids' => ['sometimes', 'array', 'required_if:profiles_mode,' . BackupPolicy::SELECTED_PROFILES],
            'profile_ids.*' => [
                'integer',
                Rule::exists($root->table(), 'id')->where($root->organizationColumn(), $this->orgId())->whereNull('deleted_at'),
            ],
            'skip_unchanged' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', Rule::enum(BackupNotify::class)],
        ];
    }
}
