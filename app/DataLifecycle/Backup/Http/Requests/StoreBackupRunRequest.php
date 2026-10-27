<?php

namespace PryTax\DataLifecycle\Backup\Http\Requests;

use Closure;
use Core\Http\Requests\Crud\StoreRequest;
use Illuminate\Validation\Rule;
use PryTax\DataLifecycle\Contracts\TenantRoot;

/**
 * Class StoreBackupRunRequest.
 *
 * Starts a manual run ("Створити копію зараз", backups.md §2.2): of every profile of the organization (`all`, the
 * default), or of the listed ones.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Requests
 */
class StoreBackupRunRequest extends StoreRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $root = app(TenantRoot::class);

        return [
            'profiles' => ['required', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== 'all' && (!is_array($value) || $value === [])) {
                    $fail(__('Choose all profiles or a list of them.'));
                }
            }],
            'profiles.*' => [
                'integer',
                Rule::exists($root->table(), 'id')->where($root->organizationColumn(), $this->orgId())->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Get the values of the fields, which the input is missing.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return ['profiles' => 'all'];
    }

    /**
     * Get ids of the listed profiles, or null for all of them.
     *
     * @return array<int, int>|null
     */
    public function profileIds(): ?array
    {
        $profiles = $this->validated('profiles');

        return is_array($profiles) ? array_values(array_unique(array_map('intval', $profiles))) : null;
    }
}
